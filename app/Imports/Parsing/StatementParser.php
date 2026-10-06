<?php

namespace App\Imports\Parsing;

use App\Models\ImportProfile;

interface StatementParser
{
    /**
     * Stream the records in a statement file.
     *
     * @return iterable<RawRow>
     *
     * @throws InvalidStatementFile when the file as a whole cannot be read with this profile.
     */
    public function parse(string $path, ImportProfile $profile): iterable;
}
