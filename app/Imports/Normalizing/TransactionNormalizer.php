<?php

namespace App\Imports\Normalizing;

use App\Enums\AmountConvention;
use App\Models\ImportProfile;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Maps a raw statement row to a NormalizedTransaction using the import profile.
 */
class TransactionNormalizer
{
    private const MAX_TEXT_LENGTH = 255;

    public function __construct(private PayeeNormalizer $payees) {}

    /**
     * @param  array<string, string>  $fields
     *
     * @throws InvalidRow
     */
    public function normalize(array $fields, ImportProfile $profile): NormalizedTransaction
    {
        $description = $this->field($fields, $profile, 'description');
        if ($description === null) {
            throw new InvalidRow('Description is empty.');
        }
        $description = mb_substr((string) preg_replace('/\s+/', ' ', $description), 0, self::MAX_TEXT_LENGTH);

        $memo = $this->field($fields, $profile, 'memo');

        return new NormalizedTransaction(
            postedOn: $this->parseDate($this->field($fields, $profile, 'date'), $profile->date_format),
            amountCents: $this->parseAmount($fields, $profile),
            descriptionRaw: $description,
            payeeNormalized: $this->payees->normalize($description),
            memo: $memo === null ? null : mb_substr($memo, 0, self::MAX_TEXT_LENGTH),
        );
    }

    private function parseDate(?string $value, string $format): CarbonImmutable
    {
        if ($value === null) {
            throw new InvalidRow('Date is empty.');
        }

        $invalid = new InvalidRow("Date \"{$value}\" does not match the expected format {$format}.");

        try {
            // "!" resets unparsed fields so no time-of-day leaks in from "now".
            $date = CarbonImmutable::rawCreateFromFormat('!'.$format, $value);
        } catch (InvalidArgumentException) {
            throw $invalid;
        }

        // Overflowing values such as 02/30 parse "successfully" but leave a warning.
        $errors = CarbonImmutable::getLastErrors();
        if ($date === null || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw $invalid;
        }

        return $date;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function parseAmount(array $fields, ImportProfile $profile): int
    {
        try {
            return match ($profile->amount_convention) {
                AmountConvention::Signed => Money::toCents($this->requiredAmount($fields, $profile)),
                AmountConvention::Inverted => -Money::toCents($this->requiredAmount($fields, $profile)),
                AmountConvention::DebitCreditColumns => $this->fromDebitCredit($fields, $profile),
            };
        } catch (InvalidArgumentException $e) {
            throw new InvalidRow($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function requiredAmount(array $fields, ImportProfile $profile): string
    {
        return $this->field($fields, $profile, 'amount') ?? throw new InvalidRow('Amount is empty.');
    }

    /**
     * Debit is money out, credit is money in. Exactly one should hold a non-zero value.
     *
     * @param  array<string, string>  $fields
     */
    private function fromDebitCredit(array $fields, ImportProfile $profile): int
    {
        $debit = $this->optionalCents($this->field($fields, $profile, 'debit'));
        $credit = $this->optionalCents($this->field($fields, $profile, 'credit'));

        return match (true) {
            $debit !== 0 && $credit !== 0 => throw new InvalidRow('Row has both a debit and a credit amount.'),
            $debit !== 0 => -abs($debit),
            $credit !== 0 => abs($credit),
            default => throw new InvalidRow('Row has no debit or credit amount.'),
        };
    }

    private function optionalCents(?string $value): int
    {
        return $value === null ? 0 : Money::toCents($value);
    }

    /**
     * The trimmed value for a normalized field name, or null when unmapped or blank.
     *
     * @param  array<string, string>  $fields
     */
    private function field(array $fields, ImportProfile $profile, string $name): ?string
    {
        $column = $profile->column_map[$name] ?? null;
        $value = $column === null ? null : trim($fields[$column] ?? '');

        return $value === '' ? null : $value;
    }
}
