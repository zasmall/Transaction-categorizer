<?php

namespace App\Jobs\Imports;

use App\Categorization\Ai\AiCategorizer;
use App\Categorization\Ai\FewShotExamples;
use App\Enums\CategorizationStatus;
use App\Models\Account;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\CategorizationService;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismServerException;
use Throwable;

/**
 * Asks the categorizer about one chunk of transactions and records its suggestions
 * after checking each one against the client's real chart of accounts.
 */
class SuggestCategoriesForChunk extends ImportStage
{
    use Batchable;

    public const QUEUE = 'ai';

    public const RATE_LIMITER = 'ai-categorization';

    /**
     * @param  list<int>  $transactionIds
     */
    public function __construct(Import $import, public array $transactionIds)
    {
        parent::__construct($import);
        $this->onQueue(self::QUEUE);
    }

    /**
     * Keep trying through rate limits and provider hiccups for a while; give up
     * after that and leave the chunk for manual review.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            new RateLimited(self::RATE_LIMITER),
            (new ThrottlesExceptions(maxAttempts: 3, decaySeconds: 60))
                ->when(fn (Throwable $e) => $e instanceof PrismRateLimitedException
                    || $e instanceof PrismProviderOverloadedException
                    || $e instanceof PrismServerException)
                ->backoff(1),
        ];
    }

    public function handle(AiCategorizer $categorizer, CategorizationService $categorizations): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $client = $this->import->client;

        // Re-check status: a person or rule may have got there first.
        $transactions = Transaction::forClient($client)
            ->whereKey($this->transactionIds)
            ->where('categorization_status', CategorizationStatus::Uncategorized)
            ->get();

        if ($transactions->isEmpty()) {
            return;
        }

        $accounts = $client->accounts()->where('is_active', true)->orderBy('code')->get();
        $result = $categorizer->suggest(
            $client,
            $transactions,
            $accounts,
            FewShotExamples::for($client, (int) config('categorization.ai.max_examples')),
        );

        $transactionsById = $transactions->keyBy('id');
        $accountsByCode = $accounts->keyBy('code');
        $recorded = 0;

        foreach ($result->suggestions as $suggestion) {
            /** @var Transaction|null $transaction */
            $transaction = $transactionsById->get($suggestion->transactionId);
            /** @var Account|null $account */
            $account = $accountsByCode->get($suggestion->accountCode);

            // Never trust the model blindly: unknown transactions or invented account
            // codes are dropped, and the transaction simply stays uncategorized.
            if ($transaction === null || $account === null) {
                continue;
            }

            if ($categorizations->suggest($transaction, $account, $suggestion->confidence, $suggestion->reason, $result->model)) {
                $recorded++;
            }
        }

        Import::query()->whereKey($this->import->id)->incrementEach(
            [
                'ai_suggested_rows' => $recorded,
                'ai_input_tokens' => $result->inputTokens,
                'ai_output_tokens' => $result->outputTokens,
            ],
            ['ai_model' => $result->model],
        );
    }
}
