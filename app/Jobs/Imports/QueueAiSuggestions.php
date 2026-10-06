<?php

namespace App\Jobs\Imports;

use App\Enums\CategorizationStatus;
use App\Enums\ImportStatus;
use Illuminate\Support\Facades\Bus;

/**
 * Splits whatever rules didn't categorize into chunks and runs them as a batch on
 * the "ai" queue. The batch is inserted into the chain, so FinalizeImport waits
 * for every chunk (failed chunks just leave their transactions uncategorized).
 */
class QueueAiSuggestions extends ImportStage
{
    public function handle(): void
    {
        if (config('categorization.ai.driver') === 'disabled') {
            return;
        }

        $ids = $this->import->transactions()
            ->where('categorization_status', CategorizationStatus::Uncategorized)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($ids->isEmpty()) {
            return;
        }

        $this->import->markStatus(ImportStatus::Suggesting);

        $jobs = $ids->chunk(max(1, (int) config('categorization.ai.chunk_size')))
            ->map(fn ($chunk) => new SuggestCategoriesForChunk($this->import, array_values($chunk->all())))
            ->values()
            ->all();

        $this->prependToChain(
            Bus::batch($jobs)
                ->name("AI suggestions for import {$this->import->id}")
                ->onQueue(SuggestCategoriesForChunk::QUEUE)
                ->allowFailures(),
        );
    }
}
