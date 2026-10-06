<?php

namespace App\Jobs\Middleware;

use App\Enums\ImportStatus;
use App\Jobs\Imports\ImportStage;
use Closure;

/**
 * Stops the rest of the chain once a stage has marked the import as failed
 * (for example, a file in the wrong format) without filling Horizon with errors.
 */
class SkipIfImportFailed
{
    public function handle(ImportStage $job, Closure $next): void
    {
        if ($job->import->refresh()->status === ImportStatus::Failed) {
            return;
        }

        $next($job);
    }
}
