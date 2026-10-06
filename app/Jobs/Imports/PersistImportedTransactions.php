<?php

namespace App\Jobs\Imports;

use App\Enums\CategorizationStatus;
use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Imports\Fingerprinter;
use App\Imports\Normalizing\NormalizedTransaction;
use App\Models\ImportRow;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fingerprints normalized rows and inserts them as transactions. Rows whose fingerprint
 * already exists for the bank account are marked as duplicates.
 */
class PersistImportedTransactions extends ImportStage
{
    public function handle(): void
    {
        $import = $this->import;
        $import->markStatus(ImportStatus::Persisting);

        // Occurrence indexes depend on file order, so every row that ever normalized is
        // fingerprinted, including ones a previous attempt already persisted.
        $fingerprinter = new Fingerprinter;

        $import->rows()
            ->whereIn('status', [ImportRowStatus::Normalized, ImportRowStatus::Imported, ImportRowStatus::Duplicate])
            ->orderBy('row_number')
            ->chunk(self::CHUNK_SIZE, function (Collection $rows) use ($fingerprinter) {
                /** @var Collection<int, ImportRow> $rows */
                $records = [];

                foreach ($rows as $row) {
                    $transaction = $row->normalizedTransaction();
                    $fingerprint = $fingerprinter->fingerprint($transaction);

                    if ($row->status === ImportRowStatus::Normalized) {
                        $records[$row->id] = $this->toRecord($row, $transaction, $fingerprint);
                    }
                }

                if ($records !== []) {
                    $this->persist($records);
                }
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $records  Keyed by import row id.
     */
    private function persist(array $records): void
    {
        DB::transaction(function () use ($records) {
            Transaction::query()->insertOrIgnore(array_values($records));

            $rowIds = array_keys($records);
            $inserted = Transaction::query()->whereIn('import_row_id', $rowIds)->pluck('import_row_id')->all();

            ImportRow::query()->whereIn('id', $inserted)->update(['status' => ImportRowStatus::Imported->value]);
            ImportRow::query()
                ->whereIn('id', array_diff($rowIds, $inserted))
                ->update(['status' => ImportRowStatus::Duplicate->value]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRecord(ImportRow $row, NormalizedTransaction $transaction, string $fingerprint): array
    {
        $now = now();

        return [
            ...$transaction->toArray(),
            'client_id' => $this->import->client_id,
            'bank_account_id' => $this->import->bank_account_id,
            'import_id' => $this->import->id,
            'import_row_id' => $row->id,
            'fingerprint' => $fingerprint,
            'categorization_status' => CategorizationStatus::Uncategorized->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
