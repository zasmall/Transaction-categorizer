<?php

namespace App\Categorization\Ai;

use App\Models\Account;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Collection;

interface AiCategorizer
{
    /**
     * Suggest an account for each transaction. May skip transactions it can't place.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Account>  $accounts  The client's active chart of accounts.
     * @param  list<array{payee: string, account_code: string}>  $examples  Recent approved decisions.
     */
    public function suggest(Client $client, Collection $transactions, Collection $accounts, array $examples): AiSuggestionBatch;
}
