<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $clients = $request->user()->clients()
            ->withCount(['bankAccounts', 'imports', 'uncategorizedTransactions'])
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client) => [
                'name' => $client->name,
                'slug' => $client->slug,
                'role' => $client->membership->role,
                'bank_accounts_count' => $client->bank_accounts_count,
                'imports_count' => $client->imports_count,
                'uncategorized_count' => $client->uncategorized_transactions_count,
            ]);

        return Inertia::render('Dashboard', ['clients' => $clients]);
    }
}
