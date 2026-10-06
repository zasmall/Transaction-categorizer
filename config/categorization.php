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
    |   "anthropic" - Claude via Prism (needs ANTHROPIC_API_KEY)
    |   "demo"      - keyword matching, no API calls; clearly labelled in the UI
    |   "disabled"  - skip the AI stage entirely
    |
    */

    'ai' => [
        'driver' => env('AI_CATEGORIZER', 'demo'),

        'model' => env('AI_MODEL', 'claude-opus-5-5'),

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
