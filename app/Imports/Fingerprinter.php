<?php

namespace App\Imports;

use App\Imports\Normalizing\NormalizedTransaction;

/**
 * Builds the dedupe key for transactions within one statement file.
 *
 * The key is the date, amount and raw description plus an occurrence index, so two
 * identical coffees on the same day stay separate while re-importing the same file
 * (or an overlapping statement) maps each row back to the same fingerprint. The raw
 * description is used rather than the cleaned payee so changing the payee rules
 * never changes existing fingerprints.
 *
 * Use one instance per file, feeding rows in file order.
 */
class Fingerprinter
{
    /** @var array<string, int> */
    private array $occurrences = [];

    public function fingerprint(NormalizedTransaction $transaction): string
    {
        $key = implode('|', [
            $transaction->postedOn->toDateString(),
            $transaction->amountCents,
            mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $transaction->descriptionRaw))),
        ]);

        $occurrence = $this->occurrences[$key] = ($this->occurrences[$key] ?? -1) + 1;

        return hash('sha256', $key.'|'.$occurrence);
    }
}
