<?php

namespace App\Categorization\Ai;

/**
 * One account suggestion for one transaction, as returned by a categorizer.
 * Not yet validated against the client's chart of accounts.
 */
final readonly class AiSuggestion
{
    public function __construct(
        public int $transactionId,
        public string $accountCode,
        public int $confidence,
        public string $reason,
    ) {}
}
