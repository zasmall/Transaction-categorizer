<?php

namespace App\Http\Controllers;

use App\Categorization\RuleEngine;
use App\Enums\CategorizationStatus;
use App\Http\Requests\Transactions\CategorizeTransactionRequest;
use App\Models\Client;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Support\AccountOptions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);

        $request->validate(['status' => ['nullable', Rule::enum(CategorizationStatus::class)]]);
        $status = $request->enum('status', CategorizationStatus::class);

        $transactions = $client->transactions()
            ->with(['bankAccount:id,name', 'account:id,code,name', 'currentCategorization.user:id,name'])
            ->when($status, fn ($query) => $query->where('categorization_status', $status))
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'posted_on' => $transaction->posted_on->toDateString(),
                'payee' => $transaction->payee_normalized,
                'description' => $transaction->description_raw,
                'amount' => Money::format($transaction->amount_cents),
                'amount_cents' => $transaction->amount_cents,
                'bank_account' => $transaction->bankAccount->name,
                'account_id' => $transaction->account_id,
                'status' => $transaction->categorization_status,
                'explanation' => $transaction->currentCategorization?->explanation(),
            ]);

        return Inertia::render('transactions/Index', [
            'client' => $client->only('name', 'slug'),
            'transactions' => $transactions,
            'status' => $status,
            'counts' => $client->transactions()
                ->selectRaw('categorization_status, count(*) as total')
                ->groupBy('categorization_status')
                ->pluck('total', 'categorization_status'),
            'accounts' => AccountOptions::for($client),
        ]);
    }

    /**
     * Categorize by hand, then offer to turn the decision into a rule when no
     * existing rule would already have made it.
     */
    public function update(CategorizeTransactionRequest $request, Client $client, Transaction $transaction, CategorizationService $categorizer): RedirectResponse
    {
        $account = $request->account();
        $categorizer->categorizeManually($transaction, $account, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categorized as :account.', ['account' => $account->name])]);

        if (RuleEngine::forClient($client)->firstMatch($transaction)?->account_id !== $account->id) {
            Inertia::flash('ruleSuggestion', [
                'payee' => $transaction->payee_normalized,
                'account_id' => $account->id,
                'account' => $account->name,
                'similar' => $this->similarUncategorizedCount($client, $transaction),
            ]);
        }

        return back();
    }

    /**
     * Other transactions a "payee contains …" rule would pick up right away.
     */
    private function similarUncategorizedCount(Client $client, Transaction $transaction): int
    {
        $needle = '%'.addcslashes(mb_strtolower($transaction->payee_normalized), '%_\\').'%';

        return $client->transactions()
            ->whereKeyNot($transaction->id)
            ->whereIn('categorization_status', [CategorizationStatus::Uncategorized, CategorizationStatus::Suggested])
            ->whereRaw("lower(payee_normalized) like ? escape '\\'", [$needle])
            ->count();
    }
}
