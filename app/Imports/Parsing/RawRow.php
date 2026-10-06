<?php

namespace App\Imports\Parsing;

/**
 * One record as read from a statement file, before any interpretation.
 */
final readonly class RawRow
{
    /**
     * @param  int  $rowNumber  Position in the file, counting the header row (1-based).
     * @param  array<string, string>  $fields  Column header (or index without headers) => cell value.
     * @param  string|null  $error  Set when the record is structurally broken and cannot be normalized.
     */
    public function __construct(
        public int $rowNumber,
        public array $fields,
        public ?string $error = null,
    ) {}
}
