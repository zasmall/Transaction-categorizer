<?php

namespace App\Categorization\Ai;

use App\Models\Account;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/**
 * Asks Claude (through Prism, using native JSON-schema structured output) to pick
 * an account for each transaction.
 *
 * The chart of accounts and examples go in the system prompt and are marked for
 * caching, since they're identical for every chunk of an import; only the
 * transactions change between requests.
 */
class ClaudeCategorizer implements AiCategorizer
{
    public function __construct(
        private string $model,
        private int $maxTokens,
        private int $timeoutSeconds,
    ) {}

    public function suggest(Client $client, Collection $transactions, Collection $accounts, array $examples): AiSuggestionBatch
    {
        $response = Prism::structured()
            ->using(Provider::Anthropic, $this->model)
            ->withSystemPrompt(
                (new SystemMessage($this->systemPrompt($client, $accounts, $examples)))
                    ->withProviderOptions(['cacheType' => 'ephemeral']),
            )
            ->withPrompt($this->transactionsPrompt($transactions))
            ->withSchema($this->schema())
            ->withMaxTokens($this->maxTokens)
            ->withClientOptions(['timeout' => $this->timeoutSeconds])
            ->asStructured();

        /** @var list<array{transaction_id?: mixed, account_code?: mixed, confidence?: mixed, reason?: mixed}> $rows */
        $rows = $response->structured['suggestions'] ?? [];

        $suggestions = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['transaction_id'] ?? null) || ! is_string($row['account_code'] ?? null)) {
                continue;
            }

            $suggestions[] = new AiSuggestion(
                transactionId: (int) $row['transaction_id'],
                accountCode: trim($row['account_code']),
                confidence: (int) round(is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 0),
                reason: is_string($row['reason'] ?? null) ? $row['reason'] : '',
            );
        }

        return new AiSuggestionBatch(
            suggestions: $suggestions,
            model: $this->model,
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  list<array{payee: string, account_code: string}>  $examples
     */
    private function systemPrompt(Client $client, Collection $accounts, array $examples): string
    {
        $chart = $accounts
            ->map(fn (Account $account) => "{$account->code} | {$account->name} | {$account->type->label()}")
            ->implode("\n");

        $history = $examples === []
            ? 'None yet.'
            : collect($examples)->map(fn (array $example) => "{$example['payee']} -> {$example['account_code']}")->implode("\n");

        return <<<PROMPT
        You categorize bank and credit card transactions for a bookkeeper. The client is "{$client->name}".

        For each transaction, choose the single best account from the chart of accounts below and return its code exactly as written. Negative amounts are money out (usually expenses); positive amounts are money in (usually income, refunds or transfers).

        Set confidence from 0 to 100. Use 80+ only when the payee clearly fits one account, 50-79 when it's a reasonable guess, and below 50 when you're unsure. A person reviews every suggestion, so an honest low confidence is more useful than a confident guess. If no account fits at all, leave the transaction out.

        Keep each reason to one short sentence a bookkeeper can scan, e.g. "Coffee shop purchase".

        Chart of accounts (code | name | type):
        {$chart}

        How this client's transactions have been categorized before (payee -> account code):
        {$history}
        PROMPT;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function transactionsPrompt(Collection $transactions): string
    {
        $lines = $transactions->map(fn (Transaction $transaction) => json_encode([
            'transaction_id' => $transaction->id,
            'date' => $transaction->posted_on->toDateString(),
            'payee' => $transaction->payee_normalized,
            'bank_description' => $transaction->description_raw,
            'memo' => $transaction->memo,
            'amount' => number_format($transaction->amount_cents / 100, 2, '.', ''),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return "Categorize these transactions (one JSON object per line):\n".$lines->implode("\n");
    }

    private function schema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'categorizations',
            description: 'Account suggestions for the transactions',
            properties: [
                new ArraySchema(
                    name: 'suggestions',
                    description: 'One entry per transaction that fits an account',
                    items: new ObjectSchema(
                        name: 'suggestion',
                        description: 'An account suggestion for one transaction',
                        properties: [
                            new NumberSchema('transaction_id', 'The transaction_id from the input'),
                            new StringSchema('account_code', 'An account code from the chart of accounts'),
                            new NumberSchema('confidence', 'Confidence from 0 to 100'),
                            new StringSchema('reason', 'One short sentence explaining the choice'),
                        ],
                        requiredFields: ['transaction_id', 'account_code', 'confidence', 'reason'],
                    ),
                ),
            ],
            requiredFields: ['suggestions'],
        );
    }
}
