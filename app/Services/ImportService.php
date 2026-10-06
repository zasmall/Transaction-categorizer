<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Jobs\Imports\FinalizeImport;
use App\Jobs\Imports\ImportStage;
use App\Jobs\Imports\NormalizeImportRows;
use App\Jobs\Imports\ParseImportFile;
use App\Jobs\Imports\PersistImportedTransactions;
use App\Models\BankAccount;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class ImportService
{
    /**
     * Store an uploaded statement and queue it through the import pipeline.
     */
    public function start(BankAccount $bankAccount, UploadedFile $file, User $user): Import
    {
        $profile = $bankAccount->importProfile
            ?? throw new LogicException("Bank account {$bankAccount->id} has no import profile.");

        $path = $file->storeAs("imports/{$bankAccount->client_id}", Str::uuid().'.csv', 'local')
            ?: throw new LogicException('The uploaded file could not be stored.');

        $import = new Import([
            'bank_account_id' => $bankAccount->id,
            'import_profile_id' => $profile->id,
            'user_id' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'file_hash' => $this->hash($file),
            'status' => ImportStatus::Pending,
        ]);
        $import->client_id = $bankAccount->client_id;
        $import->save();

        $this->dispatch($import);

        return $import;
    }

    /**
     * The most recent earlier import of the exact same file into this bank account.
     */
    public function previousImportOf(BankAccount $bankAccount, UploadedFile $file): ?Import
    {
        return Import::query()
            ->where('bank_account_id', $bankAccount->id)
            ->where('file_hash', $this->hash($file))
            ->where('status', '!=', ImportStatus::Failed)
            ->latest('id')
            ->first();
    }

    private function dispatch(Import $import): void
    {
        $importId = $import->id;

        Bus::chain([
            new ParseImportFile($import),
            new NormalizeImportRows($import),
            new PersistImportedTransactions($import),
            new FinalizeImport($import),
        ])
            ->onQueue(ImportStage::QUEUE)
            ->catch(function (Throwable $e) use ($importId) {
                Import::find($importId)?->markFailed('Something went wrong while processing this file. It can be retried from Horizon.');
            })
            ->dispatch();
    }

    private function hash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath()) ?: throw new LogicException('The uploaded file could not be read.');
    }
}
