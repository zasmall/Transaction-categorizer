<?php

namespace App\Imports\Parsing;

use InvalidArgumentException;

/**
 * Resolves a statement parser from an import profile's parser_key.
 */
class ParserRegistry
{
    /**
     * @param  array<string, StatementParser>  $parsers
     */
    public function __construct(private array $parsers) {}

    public function for(string $key): StatementParser
    {
        return $this->parsers[$key]
            ?? throw new InvalidArgumentException("No statement parser is registered for [{$key}].");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->parsers);
    }
}
