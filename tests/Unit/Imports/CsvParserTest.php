<?php

use App\Enums\AmountConvention;
use App\Imports\Parsing\CsvParser;
use App\Imports\Parsing\InvalidStatementFile;
use App\Imports\Parsing\RawRow;
use App\Models\ImportProfile;

/**
 * @param  array<string, mixed>  $attributes
 */
function csvProfile(array $attributes = []): ImportProfile
{
    return new ImportProfile([
        'name' => 'Test',
        'parser_key' => 'csv',
        'column_map' => ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount'],
        'date_format' => 'm/d/Y',
        'amount_convention' => AmountConvention::Signed,
        'delimiter' => ',',
        'has_header' => true,
        ...$attributes,
    ]);
}

/**
 * @return list<RawRow>
 */
function parseCsv(string $contents, ?ImportProfile $profile = null): array
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $contents);

    try {
        return iterator_to_array((new CsvParser)->parse($path, $profile ?? csvProfile()), false);
    } finally {
        unlink($path);
    }
}

test('it maps cells to headers and numbers rows from the top of the file', function () {
    $rows = parseCsv("Date,Description,Amount\n01/05/2026,\"COFFEE, LARGE\",-5.75\n\n01/06/2026,RENT,-1200\n");

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->rowNumber)->toBe(2)
        ->and($rows[0]->fields)->toBe(['Date' => '01/05/2026', 'Description' => 'COFFEE, LARGE', 'Amount' => '-5.75'])
        ->and($rows[1]->rowNumber)->toBe(4)
        ->and($rows[1]->error)->toBeNull();
});

test('it strips a byte order mark and tolerates trailing delimiters', function () {
    $rows = parseCsv("\u{FEFF}Date,Description,Amount\n01/05/2026,COFFEE,-5.75,,\n");

    expect($rows[0]->fields)->toBe(['Date' => '01/05/2026', 'Description' => 'COFFEE', 'Amount' => '-5.75'])
        ->and($rows[0]->error)->toBeNull();
});

test('it flags rows with the wrong number of columns instead of failing the file', function () {
    $rows = parseCsv("Date,Description,Amount\n01/05/2026,COFFEE\n01/06/2026,RENT,-1200,EXTRA\n");

    expect($rows[0]->error)->toBe('Expected 3 columns but found 2.')
        ->and($rows[0]->fields['Amount'])->toBe('')
        ->and($rows[1]->error)->toBe('Expected 3 columns but found 4.');
});

test('it supports other delimiters and files without a header row', function () {
    $profile = csvProfile([
        'delimiter' => ';',
        'has_header' => false,
        'column_map' => ['date' => '0', 'description' => '1', 'amount' => '2'],
    ]);

    $rows = parseCsv("01/05/2026;COFFEE;-5,75\n", $profile);

    expect($rows[0]->rowNumber)->toBe(1)
        ->and($rows[0]->fields)->toBe(['0' => '01/05/2026', '1' => 'COFFEE', '2' => '-5,75']);
});

test('it rejects files missing a mapped column', function () {
    parseCsv("Date,Payee,Value\n01/05/2026,COFFEE,-5.75\n");
})->throws(InvalidStatementFile::class, 'Missing expected column(s): Description, Amount.');

test('it rejects empty files', function () {
    parseCsv("\n\n");
})->throws(InvalidStatementFile::class, 'The file is empty.');
