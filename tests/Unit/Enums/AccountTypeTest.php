<?php

use App\Enums\AccountType;

test('only asset and liability accounts can back a bank account', function (AccountType $type, bool $expected) {
    expect($type->canBackBankAccount())->toBe($expected);
})->with([
    'asset' => [AccountType::Asset, true],
    'liability' => [AccountType::Liability, true],
    'equity' => [AccountType::Equity, false],
    'income' => [AccountType::Income, false],
    'expense' => [AccountType::Expense, false],
]);
