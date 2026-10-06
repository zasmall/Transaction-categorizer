<?php

namespace App\Jobs\Imports;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Imports\Normalizing\InvalidRow;
use App\Imports\Normalizing\TransactionNormalizer;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Converts each pending row to canonical form. Bad rows are marked failed with a reason;
 * they never fail the import.
 */
class NormalizeImportRows extends ImportStage
{
    public function handle(TransactionNormalizer $normalizer): void
    {
        $this->import->markStatus(ImportStatus::Normalizing);
        $profile = $this->import->importProfile;

        $this->import->rows()
            ->where('status', ImportRowStatus::Pending)
            ->chunkById(self::CHUNK_SIZE, function (Collection $rows) use ($normalizer, $profile) {
                DB::transaction(function () use ($rows, $normalizer, $profile) {
                    /** @var Collection<int, ImportRow> $rows */
                    foreach ($rows as $row) {
                        try {
                            $row->normalized = $normalizer->normalize($row->raw, $profile)->toArray();
                            $row->status = ImportRowStatus::Normalized;
                        } catch (InvalidRow $e) {
                            $row->status = ImportRowStatus::Failed;
                            $row->error = $e->getMessage();
                        }

                        $row->save();
                    }
                });
            });
    }
}
