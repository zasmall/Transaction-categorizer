<?php

namespace App\Imports\Parsing;

use App\Models\ImportProfile;
use SplFileObject;

/**
 * Reads delimited text files, using the profile's column map to find the fields it needs.
 */
class CsvParser implements StatementParser
{
    private const UTF8_BOM = "\u{FEFF}";

    public function parse(string $path, ImportProfile $profile): iterable
    {
        $file = new SplFileObject($path, 'r');
        $file->setCsvControl($profile->delimiter, '"', '');

        $headers = null;
        $rowNumber = 0;

        while (! $file->eof()) {
            $cells = $file->fgetcsv();
            $rowNumber++;

            if ($cells === false || $this->isBlank($cells)) {
                continue;
            }

            /** @var list<string> $cells */
            if ($headers === null) {
                $headers = $profile->has_header
                    ? $this->readHeaders($cells, $profile)
                    : array_map(strval(...), array_keys($cells));

                if ($profile->has_header) {
                    continue;
                }
            }

            yield $this->toRawRow($rowNumber, $headers, $cells);
        }

        if ($headers === null) {
            throw new InvalidStatementFile('The file is empty.');
        }
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function readHeaders(array $cells, ImportProfile $profile): array
    {
        $cells[0] = str_replace(self::UTF8_BOM, '', $cells[0]);
        $headers = array_map(trim(...), $cells);

        $missing = array_diff(array_values($profile->column_map), $headers);

        if ($missing !== []) {
            throw new InvalidStatementFile(sprintf(
                'Missing expected column(s): %s. Is "%s" the right format for this file?',
                implode(', ', $missing),
                $profile->name,
            ));
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $cells
     */
    private function toRawRow(int $rowNumber, array $headers, array $cells): RawRow
    {
        // Some banks (Chase, for one) end every data line with a trailing delimiter.
        while (count($cells) > count($headers) && trim((string) end($cells)) === '') {
            array_pop($cells);
        }

        $error = count($cells) === count($headers)
            ? null
            : sprintf('Expected %d columns but found %d.', count($headers), count($cells));

        $cells = array_pad(array_slice($cells, 0, count($headers)), count($headers), '');

        return new RawRow($rowNumber, array_combine($headers, $cells), $error);
    }

    /**
     * @param  array<int, string|null>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
