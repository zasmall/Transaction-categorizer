<?php

namespace App\Http\Controllers;

use App\Enums\ImportRowStatus;
use App\Http\Requests\Imports\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Import;
use App\Models\ImportRow;
use App\Services\ImportService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function index(Client $client): Response
    {
        Gate::authorize('view', $client);

        return Inertia::render('imports/Index', [
            'client' => $client->only('name', 'slug'),
            'bankAccounts' => $client->bankAccounts()
                ->with('importProfile')
                ->orderBy('name')
                ->get()
                ->map(fn (BankAccount $bankAccount) => [
                    'id' => $bankAccount->id,
                    'name' => $bankAccount->name,
                    'last4' => $bankAccount->last4,
                    'profile' => $bankAccount->importProfile?->name,
                ]),
            'imports' => ImportResource::collection(
                $client->imports()->with('bankAccount', 'user')->latest('id')->limit(25)->get(),
            )->resolve(),
            'maxFileKilobytes' => StoreImportRequest::MAX_FILE_KILOBYTES,
        ]);
    }

    public function store(StoreImportRequest $request, Client $client, ImportService $imports): RedirectResponse
    {
        $import = $imports->start($request->bankAccount(), $request->statement(), $request->user());

        return to_route('clients.imports.show', [$client, $import]);
    }

    public function show(Client $client, Import $import): Response
    {
        Gate::authorize('view', $client);

        $import->load('bankAccount', 'importProfile', 'user');

        return Inertia::render('imports/Show', [
            'client' => $client->only('name', 'slug'),
            'statementImport' => (new ImportResource($import))->resolve(),
            'rows' => fn () => $import->rows()
                ->with('transaction:id,import_row_id')
                ->orderByRaw('case when status = ? then 0 else 1 end', [ImportRowStatus::Failed->value])
                ->orderBy('row_number')
                ->limit(200)
                ->get()
                ->map(fn (ImportRow $row) => [
                    'id' => $row->id,
                    'row_number' => $row->row_number,
                    'status' => $row->status,
                    'error' => $row->error,
                    'raw' => $row->raw,
                    'posted_on' => $row->normalized['posted_on'] ?? null,
                    'payee' => $row->normalized['payee_normalized'] ?? null,
                    'description' => $row->normalized['description_raw'] ?? null,
                    'amount' => isset($row->normalized) ? Money::format($row->normalized['amount_cents']) : null,
                    'amount_cents' => $row->normalized['amount_cents'] ?? null,
                ]),
        ]);
    }
}
