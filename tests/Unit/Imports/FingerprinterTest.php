<?php

use App\Imports\Fingerprinter;
use App\Imports\Normalizing\NormalizedTransaction;
use Carbon\CarbonImmutable;

function normalizedTransaction(string $date = '2026-01-05', int $cents = -575, string $description = 'SQ *BLUE BOTTLE'): NormalizedTransaction
{
    return new NormalizedTransaction(CarbonImmutable::parse($date), $cents, $description, 'Blue Bottle');
}

test('identical rows in one file get different fingerprints', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint(normalizedTransaction()))
        ->not->toBe($fingerprinter->fingerprint(normalizedTransaction()));
});

test('the same file always produces the same fingerprints', function () {
    $first = new Fingerprinter;
    $second = new Fingerprinter;

    $rows = [normalizedTransaction(), normalizedTransaction(), normalizedTransaction(cents: -1000)];

    expect(array_map($first->fingerprint(...), $rows))->toBe(array_map($second->fingerprint(...), $rows));
});

test('fingerprints ignore case and spacing in the description', function () {
    expect((new Fingerprinter)->fingerprint(normalizedTransaction(description: 'SQ  *Blue  Bottle ')))
        ->toBe((new Fingerprinter)->fingerprint(normalizedTransaction(description: 'sq *blue bottle')));
});

test('fingerprints change with date, amount or description', function () {
    $base = (new Fingerprinter)->fingerprint(normalizedTransaction());

    expect((new Fingerprinter)->fingerprint(normalizedTransaction(date: '2026-01-06')))->not->toBe($base)
        ->and((new Fingerprinter)->fingerprint(normalizedTransaction(cents: -576)))->not->toBe($base)
        ->and((new Fingerprinter)->fingerprint(normalizedTransaction(description: 'SQ *PEETS')))->not->toBe($base);
});
