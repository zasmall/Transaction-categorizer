<?php

namespace App\Jobs\Imports;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;

/**
 * Records the final row counts and status.
 */
class FinalizeImport extends ImportStage
{
    public function handle(): void
    {
        $counts = $this->import->rows()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $failed = (int) ($counts[ImportRowStatus::Failed->value] ?? 0);

        $this->import->update([
            'status' => $failed > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
            'imported_rows' => (int) ($counts[ImportRowStatus::Imported->value] ?? 0),
            'duplicate_rows' => (int) ($counts[ImportRowStatus::Duplicate->value] ?? 0),
            'failed_rows' => $failed,
            'finished_at' => now(),
        ]);
    }
}
