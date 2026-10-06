<?php

namespace App\Enums;

/**
 * How a bank statement represents money in and money out.
 */
enum AmountConvention: string
{
    /** One amount column: negative is money out, positive is money in. */
    case Signed = 'signed';

    /** Separate debit (money out) and credit (money in) columns, both positive. */
    case DebitCreditColumns = 'debit_credit_columns';

    /** One amount column with the sign flipped: positive is a charge (common on credit cards). */
    case Inverted = 'inverted';
}
