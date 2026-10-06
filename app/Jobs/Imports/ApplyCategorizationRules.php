<?php

namespace App\Jobs\Imports;

use App\Enums\ImportStatus;
use App\Services\CategorizationService;

/**
 * Categorizes the import's new transactions with the client's rules. Whatever no rule
 * matches is left for AI suggestions and review.
 */
class ApplyCategorizationRules extends ImportStage
{
    public function handle(CategorizationService $categorizer): void
    {
        $this->import->markStatus(ImportStatus::Categorizing);

        $categorizer->applyRules($this->import->client, $this->import->transactions()->getQuery());
    }
}
