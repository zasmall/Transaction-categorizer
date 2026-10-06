<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is always integer cents. Parsing works on the string so floats never get involved.
 */
final class Money
{
    private const AMOUNT_PATTERN = '/^(?<sign>[+-])?(?<whole>\d{1,3}(?:,\d{3})+|\d+)(?:\.(?<fraction>\d{1,2}))?$/';

    /**
     * Parse amounts such as "1,234.56", "-12.5", "$40", "(45.00)" or "-$5.00" into cents.
     *
     * @throws InvalidArgumentException
     */
    public static function toCents(string $amount): int
    {
        $value = str_replace([' ', '$'], '', trim($amount));

        $negative = false;
        if (preg_match('/^\((.+)\)$/', $value, $parenthesized)) {
            $negative = true;
            $value = $parenthesized[1];
        }

        if (! preg_match(self::AMOUNT_PATTERN, $value, $parts)) {
            throw new InvalidArgumentException("\"{$amount}\" is not a valid amount.");
        }

        $whole = str_replace(',', '', $parts['whole']);
        if (strlen(ltrim($whole, '0')) > 15) {
            throw new InvalidArgumentException("\"{$amount}\" is too large.");
        }

        $cents = (int) $whole * 100 + (int) str_pad($parts['fraction'] ?? '', 2, '0');

        if ($negative || $parts['sign'] === '-') {
            $cents = -$cents;
        }

        return $cents;
    }

    public static function format(int $cents): string
    {
        $formatted = '$'.number_format(abs($cents) / 100, 2);

        return $cents < 0 ? '-'.$formatted : $formatted;
    }
}
