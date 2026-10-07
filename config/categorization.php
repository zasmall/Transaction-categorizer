<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI fallback
    |--------------------------------------------------------------------------
    |
    | Transactions no rule matched are sent to a categorizer that suggests an
    | account. Suggestions are never approved automatically.
    |
    | Drivers:
    |   "demo"     - keyword matching, no API calls; clearly labelled in the UI
    |   "prism"    - Claude via Prism (needs ANTHROPIC_API_KEY)
    |   "sdk"      - Claude via the official Anthropic PHP SDK (needs ANTHROPIC_API_KEY)
    |   "disabled" - skip the AI stage entirely
    |
    | Both Claude drivers send the same prompt and schema (CategorizationPrompt).
    |
    */

    'ai' => [
        'driver' => env('AI_CATEGORIZER', 'demo'),

        'api_key' => env('ANTHROPIC_API_KEY'),

        // The least expensive current model; bulk categorization is a simple, high-volume task.
        'model' => env('AI_MODEL', 'claude-haiku-4-5'),

        // Transactions per request, and requests per minute across all workers.
        'chunk_size' => (int) env('AI_CHUNK_SIZE', 25),
        'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 20),

        // Recent approved decisions included as few-shot examples.
        'max_examples' => 20,

        // Output budget per request. Adaptive thinking counts against this, so keep headroom.
        'max_tokens' => 16000,

        'timeout_seconds' => 120,
    ],

];
