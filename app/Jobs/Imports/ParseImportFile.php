<?php

namespace App\Jobs\Imports;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Imports\Parsing\InvalidStatementFile;
use App\Imports\Parsing\ParserRegistry;
use App\Imports\Parsing\RawRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reads the stored file and stages every record in import_rows.
 */
class ParseImportFile extends ImportStage
{
    public function handle(ParserRegistry $parsers): void
    {
        $import = $this->import;

        // A previous attempt finished parsing. Keep those rows: later stages may already
        // have linked transactions to them.
        if ($import->total_rows > 0) {
            return;
        }

        $import->update(['status' => ImportStatus::Parsing, 'started_at' => now(), 'error' => null]);

        // Clear anything a partial attempt left behind so rows are never staged twice.
        $import->rows()->delete();

        $profile = $import->importProfile;
        $path = Storage::disk('local')->path($import->stored_path);

        try {
            $total = 0;
            $batch = [];

            foreach ($parsers->for($profile->parser_key)->parse($path, $profile) as $row) {
                $batch[] = $this->toRecord($row);
                $total++;

                if (count($batch) === self::CHUNK_SIZE) {
                    DB::table('import_rows')->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table('import_rows')->insert($batch);
            }

            if ($total === 0) {
                throw new InvalidStatementFile('The file has no transaction rows.');
            }
        } catch (InvalidStatementFile $e) {
            $import->rows()->delete();
            $import->markFailed($e->getMessage());

            return;
        }

        $import->update(['total_rows' => $total]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRecord(RawRow $row): array
    {
        $now = now();

        return [
            'import_id' => $this->import->id,
            'row_number' => $row->rowNumber,
            'raw' => json_encode($row->fields, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'status' => $row->error === null ? ImportRowStatus::Pending->value : ImportRowStatus::Failed->value,
            'error' => $row->error,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
