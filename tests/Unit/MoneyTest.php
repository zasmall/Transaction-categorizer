<?php

use App\Support\Money;

test('it parses amounts to integer cents', function (string $input, int $cents) {
    expect(Money::toCents($input))->toBe($cents);
})->with([
    ['12.34', 1234],
    ['-12.34', -1234],
    ['+12.34', 1234],
    ['1,234.56', 123456],
    ['-1,234,567.89', -123456789],
    ['$40', 4000],
    ['-$5.00', -500],
    ['$-5.00', -500],
    ['(45.00)', -4500],
    ['($1,000.10)', -100010],
    ['12.5', 1250],
    [' 7.00 ', 700],
    ['0.10', 10],
    ['0', 0],
    // Floats would turn this into 1914 cents (19.14 * 100 = 1913.9999...).
    ['19.14', 1914],
]);

test('it rejects malformed amounts', function (string $input) {
    expect(fn () => Money::toCents($input))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '12.345', '1,23.00', '12..3', '--5', '1e5', '9999999999999999.00']);

test('it formats cents for display', function () {
    expect(Money::format(123456))->toBe('$1,234.56')
        ->and(Money::format(-575))->toBe('-$5.75')
        ->and(Money::format(0))->toBe('$0.00');
});
