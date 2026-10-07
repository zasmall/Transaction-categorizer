<?php

namespace App\Categorization\Ai;

use App\Models\Account;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * The prompt, output schema and response parsing shared by every Claude-backed
 * categorizer, so the Prism and official-SDK implementations differ only in
 * transport.
 *
 * The chart of accounts and examples go in the system prompt (identical for every
 * chunk of an import, so it's worth caching); only the transactions change.
 */
final class CategorizationPrompt
{
    /**
     * @param  Collection<int, Account>  $accounts
     * @param  list<array{payee: string, account_code: string}>  $examples
     */
    public static function system(Client $client, Collection $accounts, array $examples): string
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
    public static function transactions(Collection $transactions): string
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

    /**
     * JSON schema for structured output. Every object closes additionalProperties,
     * as structured outputs require.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'description' => 'One entry per transaction that fits an account',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'transaction_id' => ['type' => 'integer', 'description' => 'The transaction_id from the input'],
                            'account_code' => ['type' => 'string', 'description' => 'An account code from the chart of accounts'],
                            'confidence' => ['type' => 'integer', 'description' => 'Confidence from 0 to 100'],
                            'reason' => ['type' => 'string', 'description' => 'One short sentence explaining the choice'],
                        ],
                        'required' => ['transaction_id', 'account_code', 'confidence', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['suggestions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Turn the decoded response into suggestions, skipping malformed entries.
     * Whether codes and ids are real is checked later, against the database.
     *
     * @param  array<mixed>  $structured
     * @return list<AiSuggestion>
     */
    public static function parse(array $structured): array
    {
        $rows = $structured['suggestions'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $suggestions = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['transaction_id'] ?? null) || ! is_string($row['account_code'] ?? null)) {
                continue;
            }

            $suggestions[] = new AiSuggestion(
                transactionId: (int) $row['transaction_id'],
                accountCode: trim($row['account_code']),
                confidence: (int) round(is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 0),
                reason: is_string($row['reason'] ?? null) ? $row['reason'] : '',
            );
        }

        return $suggestions;
    }
}
