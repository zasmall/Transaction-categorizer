<?php

namespace App\Categorization\Ai;

use App\Enums\CategorizationStatus;
use App\Models\Client;
use App\Models\Transaction;

/**
 * Recent approved decisions for a client, one per payee, used to teach the
 * categorizer this client's habits.
 */
final class FewShotExamples
{
    /**
     * @return list<array{payee: string, account_code: string}>
     */
    public static function for(Client $client, int $limit): array
    {
        return array_values(Transaction::forClient($client)
            ->where('categorization_status', CategorizationStatus::Approved)
            ->whereNotNull('account_id')
            ->with('account:id,code')
            ->latest('updated_at')
            ->limit($limit * 10)
            ->get()
            ->unique(fn (Transaction $transaction) => mb_strtolower($transaction->payee_normalized))
            ->take($limit)
            ->map(fn (Transaction $transaction) => [
                'payee' => $transaction->payee_normalized,
                'account_code' => $transaction->account->code ?? '',
            ])
            ->all());
    }
}
