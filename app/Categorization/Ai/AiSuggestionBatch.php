<?php

namespace App\Categorization\Ai;

/**
 * The result of one categorizer call.
 */
final readonly class AiSuggestionBatch
{
    /**
     * @param  list<AiSuggestion>  $suggestions
     */
    public function __construct(
        public array $suggestions,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
