<?php

namespace App\Http\Controllers;

use App\Categorization\LearnedRuleSuggestions;
use App\Enums\CategorizationStatus;
use App\Http\Requests\Review\BulkApproveRequest;
use App\Models\Client;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Support\AccountOptions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything that still needs a person, least certain first: uncategorized
 * transactions, then AI suggestions from lowest to highest confidence.
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Client $client, LearnedRuleSuggestions $learnedRules): Response
    {
        Gate::authorize('view', $client);

        $queue = $client->transactions()
            ->select('transactions.*')
            ->leftJoin('categorizations', fn ($join) => $join
                ->on('categorizations.transaction_id', '=', 'transactions.id')
                ->where('categorizations.is_current', true))
            ->whereIn('transactions.categorization_status', [CategorizationStatus::Uncategorized, CategorizationStatus::Suggested])
            ->with(['bankAccount:id,name', 'currentCategorization'])
            // Uncategorized first (no suggestion at all), then lowest confidence.
            ->orderByRaw('coalesce(categorizations.confidence, -1) asc')
            ->orderBy('transactions.posted_on')
            ->orderBy('transactions.id')
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
                'confidence' => $transaction->currentCategorization?->confidence,
                'explanation' => $transaction->currentCategorization?->explanation(),
                'ai_reason' => $transaction->currentCategorization?->ai_reason,
            ]);

        return Inertia::render('review/Index', [
            'client' => $client->only('name', 'slug'),
            'queue' => $queue,
            'accounts' => AccountOptions::for($client),
            'learnedRules' => $learnedRules->for($client)->count(),
            'maxBulk' => BulkApproveRequest::MAX_PER_REQUEST,
        ]);
    }

    /**
     * Approve the suggested account on each selected transaction. Anything that is
     * no longer a suggestion (approved meanwhile, or never suggested) is skipped.
     */
    public function approve(BulkApproveRequest $request, Client $client, CategorizationService $categorizer): RedirectResponse
    {
        $transactions = $client->transactions()
            ->whereKey($request->transactionIds())
            ->where('categorization_status', CategorizationStatus::Suggested)
            ->whereNotNull('account_id')
            ->with('account')
            ->get();

        DB::transaction(function () use ($transactions, $categorizer, $request) {
            foreach ($transactions as $transaction) {
                $categorizer->categorizeManually($transaction, $transaction->account, $request->user());
            }
        });

        $skipped = count($request->transactionIds()) - $transactions->count();
        $message = trans_choice('{1} Approved 1 suggestion.|[2,*] Approved :count suggestions.', $transactions->count());
        if ($transactions->isEmpty()) {
            $message = __('Nothing to approve: none of those are suggestions any more.');
        } elseif ($skipped > 0) {
            $message .= ' '.trans_choice('{1} Skipped 1 that wasn\'t a suggestion.|[2,*] Skipped :count that weren\'t suggestions.', $skipped);
        }

        Inertia::flash('toast', ['type' => $transactions->isEmpty() ? 'info' : 'success', 'message' => $message]);

        return back();
    }
}
