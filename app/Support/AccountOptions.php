<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Client;

/**
 * A client's active accounts shaped for select inputs, in chart-of-accounts order.
 */
final class AccountOptions
{
    /**
     * @return list<array{id: int, code: string, name: string, type: string}>
     */
    public static function for(Client $client): array
    {
        return array_values($client->accounts()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->label(),
            ])
            ->all());
    }
}
