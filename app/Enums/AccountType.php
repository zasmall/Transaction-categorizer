<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Whether a bank or credit card account can post to a ledger account of this type.
     */
    public function canBackBankAccount(): bool
    {
        return $this === self::Asset || $this === self::Liability;
    }
}
