<?php

namespace App\Relay;

use App\Models\Categorization;

/**
 * The `transaction.categorized` contract agreed with Cashflow Insights.
 *
 * Ids are sent as strings and treated as opaque by the receiver. `categorized_at` is the
 * version time: the receiver orders updates by it, not by when the relay received them.
 */
final class TransactionCategorizedEvent
{
    public const TYPE = 'transaction.categorized';

    public static function idempotencyKey(Categorization $categorization): string
    {
        return "categorization-{$categorization->id}";
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Categorization $categorization): array
    {
        $transaction = $categorization->transaction;

        return [
            'entity_id' => (string) $transaction->client_id,
            'transaction' => [
                'id' => (string) $transaction->id,
                'account_id' => (string) $transaction->bank_account_id,
                'posted_on' => $transaction->posted_on->toDateString(),
                'amount' => self::decimal($transaction->amount_cents),
                'currency' => (string) config('services.relay_publisher.currency'),
                'description' => $transaction->description_raw,
                'vendor' => $transaction->payee_normalized,
                'category' => $categorization->account->name,
                'categorized_at' => $categorization->created_at?->toIso8601ZuluString(),
            ],
        ];
    }

    /**
     * Signed integer cents to an exact decimal string: -12999 becomes "-129.99". Never a float.
     */
    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
