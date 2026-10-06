<?php

namespace App\Jobs\Imports;

use App\Jobs\Middleware\SkipIfImportFailed;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One stage of the import pipeline. Stages run as a chain on the "imports" queue,
 * and each one is safe to retry from Horizon.
 */
abstract class ImportStage implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'imports';

    /** Rows handled per database round trip. */
    protected const CHUNK_SIZE = 500;

    public function __construct(public Import $import)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SkipIfImportFailed];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->import->queueTags();
    }
}
