<?php

namespace App\Services;

use App\Categorization\RuleEngine;
use App\Enums\CategorizationMethod;
use App\Enums\CategorizationStatus;
use App\Models\Account;
use App\Models\Categorization;
use App\Models\CategorizationRule;
use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place categorizations are written, so the audit trail stays consistent.
 */
class CategorizationService
{
    private const CHUNK_SIZE = 500;

    /**
     * A person picks the account. Always approved, and it overrides anything earlier.
     */
    public function categorizeManually(Transaction $transaction, Account $account, User $user): Categorization
    {
        return $this->record($transaction, $account, CategorizationStatus::Approved, [
            'method' => CategorizationMethod::Manual,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Run the client's rules over uncategorized and AI-suggested transactions.
     * Approved transactions are never touched: a person's decision always wins.
     *
     * @param  Builder<Transaction>  $transactions
     * @return int How many transactions a rule categorized.
     */
    public function applyRules(Client $client, Builder $transactions): int
    {
        $engine = RuleEngine::forClient($client);
        if ($engine->isEmpty()) {
            return 0;
        }

        /** @var array<int, int> $hits rule id => matches */
        $hits = [];

        $transactions->clone()
            ->where('client_id', $client->id)
            ->whereIn('categorization_status', [CategorizationStatus::Uncategorized, CategorizationStatus::Suggested])
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($engine, &$hits) {
                DB::transaction(function () use ($chunk, $engine, &$hits) {
                    /** @var Collection<int, Transaction> $chunk */
                    foreach ($chunk as $transaction) {
                        $rule = $engine->firstMatch($transaction);
                        if ($rule === null) {
                            continue;
                        }

                        $this->applyRule($transaction, $rule);
                        $hits[$rule->id] = ($hits[$rule->id] ?? 0) + 1;
                    }
                });
            });

        foreach ($hits as $ruleId => $count) {
            CategorizationRule::query()->whereKey($ruleId)->incrementEach(
                ['hits_count' => $count],
                ['last_matched_at' => now()],
            );
        }

        return array_sum($hits);
    }

    private function applyRule(Transaction $transaction, CategorizationRule $rule): void
    {
        $this->record($transaction, $rule->account, CategorizationStatus::Approved, [
            'method' => CategorizationMethod::Rule,
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
        ]);
    }

    /**
     * Append a new categorization and make it current.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(Transaction $transaction, Account $account, CategorizationStatus $status, array $attributes): Categorization
    {
        if ($account->client_id !== $transaction->client_id) {
            throw new InvalidArgumentException('A transaction can only be categorized to its own client\'s accounts.');
        }

        return DB::transaction(function () use ($transaction, $account, $status, $attributes) {
            Categorization::query()
                ->where('transaction_id', $transaction->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $categorization = Categorization::create([
                ...$attributes,
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'is_current' => true,
            ]);

            $transaction->update([
                'account_id' => $account->id,
                'categorization_status' => $status,
            ]);

            return $categorization;
        });
    }
}
