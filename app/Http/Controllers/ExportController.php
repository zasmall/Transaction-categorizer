<?php

namespace App\Http\Controllers;

use App\Enums\CategorizationStatus;
use App\Exports\QuickBooksJournalCsv;
use App\Http\Requests\Exports\ExportFilterRequest;
use App\Models\BankAccount;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * QuickBooks Online export. Downloading has no side effects; the bookkeeper marks
 * the batch as exported once QuickBooks has accepted it, so a failed import can
 * simply be downloaded again.
 */
class ExportController extends Controller
{
    public function index(ExportFilterRequest $request, Client $client): Response
    {
        return Inertia::render('exports/Index', [
            'client' => $client->only('name', 'slug'),
            'filters' => $request->filters(),
            'bankAccounts' => $client->bankAccounts()->orderBy('name')->get()
                ->map(fn (BankAccount $bankAccount) => ['id' => $bankAccount->id, 'name' => $bankAccount->name]),
            'summary' => [
                'ready' => $request->exportable()->count(),
                'needs_review' => $request->inRange()->where('categorization_status', '!=', CategorizationStatus::Approved)->count(),
                'already_exported' => $request->inRange()->whereNotNull('exported_at')->count(),
            ],
        ]);
    }

    public function download(ExportFilterRequest $request, Client $client, QuickBooksJournalCsv $csv): StreamedResponse
    {
        $transactions = $request->exportable()
            ->with(['bankAccount.ledgerAccount', 'account'])
            ->orderBy('posted_on')
            ->orderBy('id');

        $filename = sprintf('%s-quickbooks-journal-%s.csv', Str::slug($client->name), now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($transactions, $csv) {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            // cursor() streams one query in date order without loading everything into memory.
            $csv->write($transactions->cursor(), $stream);
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function markExported(ExportFilterRequest $request, Client $client): RedirectResponse
    {
        $count = $request->exportable()->whereNull('exported_at')->update(['exported_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{0} Nothing new to mark as exported.|{1} Marked 1 transaction as exported.|[2,*] Marked :count transactions as exported.',
            $count,
        )]);

        return back();
    }
}
