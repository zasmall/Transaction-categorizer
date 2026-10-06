<?php

use App\Enums\AmountConvention;
use App\Imports\Normalizing\InvalidRow;
use App\Imports\Normalizing\PayeeNormalizer;
use App\Imports\Normalizing\TransactionNormalizer;
use App\Models\ImportProfile;

function normalizerProfile(AmountConvention $convention = AmountConvention::Signed, string $dateFormat = 'm/d/Y'): ImportProfile
{
    return new ImportProfile([
        'name' => 'Test',
        'column_map' => $convention === AmountConvention::DebitCreditColumns
            ? ['date' => 'Date', 'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit', 'memo' => 'Memo']
            : ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount', 'memo' => 'Memo'],
        'date_format' => $dateFormat,
        'amount_convention' => $convention,
    ]);
}

function normalizer(): TransactionNormalizer
{
    return new TransactionNormalizer(new PayeeNormalizer);
}

test('it normalizes a signed row', function () {
    $transaction = normalizer()->normalize(
        ['Date' => '01/05/2026', 'Description' => ' SQ *BLUE  BOTTLE #0423 ', 'Amount' => '-5.75', 'Memo' => ''],
        normalizerProfile(),
    );

    expect($transaction->postedOn->toDateString())->toBe('2026-01-05')
        ->and($transaction->postedOn->toTimeString())->toBe('00:00:00')
        ->and($transaction->amountCents)->toBe(-575)
        ->and($transaction->descriptionRaw)->toBe('SQ *BLUE BOTTLE #0423')
        ->and($transaction->payeeNormalized)->toBe('Blue Bottle')
        ->and($transaction->memo)->toBeNull();
});

test('inverted amounts flip the sign', function () {
    $transaction = normalizer()->normalize(
        ['Date' => '01/02/2026', 'Description' => 'DELTA AIR LINES', 'Amount' => '412.30', 'Memo' => 'Flight'],
        normalizerProfile(AmountConvention::Inverted),
    );

    expect($transaction->amountCents)->toBe(-41230)
        ->and($transaction->memo)->toBe('Flight');
});

test('debit and credit columns become signed amounts', function (string $debit, string $credit, int $cents) {
    $transaction = normalizer()->normalize(
        ['Date' => '2026-01-04', 'Description' => 'AMAZON', 'Debit' => $debit, 'Credit' => $credit],
        normalizerProfile(AmountConvention::DebitCreditColumns, 'Y-m-d'),
    );

    expect($transaction->amountCents)->toBe($cents);
})->with([
    'debit is money out' => ['64.07', '', -6407],
    'credit is money in' => ['', '500.00', 50000],
    'zero in the other column is ignored' => ['0.00', '19.99', 1999],
]);

test('bad rows raise InvalidRow with a readable reason', function (array $fields, AmountConvention $convention, string $message) {
    expect(fn () => normalizer()->normalize($fields, normalizerProfile($convention)))
        ->toThrow(InvalidRow::class, $message);
})->with([
    'impossible date' => [['Date' => '13/45/2026', 'Description' => 'X', 'Amount' => '1'], AmountConvention::Signed, 'Date "13/45/2026" does not match the expected format m/d/Y.'],
    'wrong date format' => [['Date' => '2026-01-05', 'Description' => 'X', 'Amount' => '1'], AmountConvention::Signed, 'does not match the expected format'],
    'overflowing day' => [['Date' => '02/30/2026', 'Description' => 'X', 'Amount' => '1'], AmountConvention::Signed, 'does not match the expected format'],
    'missing date' => [['Date' => '', 'Description' => 'X', 'Amount' => '1'], AmountConvention::Signed, 'Date is empty.'],
    'missing description' => [['Date' => '01/05/2026', 'Description' => ' ', 'Amount' => '1'], AmountConvention::Signed, 'Description is empty.'],
    'missing amount' => [['Date' => '01/05/2026', 'Description' => 'X', 'Amount' => ''], AmountConvention::Signed, 'Amount is empty.'],
    'garbage amount' => [['Date' => '01/05/2026', 'Description' => 'X', 'Amount' => 'N/A'], AmountConvention::Signed, '"N/A" is not a valid amount.'],
    'both debit and credit' => [['Date' => '01/05/2026', 'Description' => 'X', 'Debit' => '1', 'Credit' => '2'], AmountConvention::DebitCreditColumns, 'both a debit and a credit'],
    'neither debit nor credit' => [['Date' => '01/05/2026', 'Description' => 'X', 'Debit' => '', 'Credit' => ''], AmountConvention::DebitCreditColumns, 'no debit or credit'],
]);
