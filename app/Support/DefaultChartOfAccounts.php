<?php

namespace App\Support;

use App\Enums\AccountType;

/**
 * A starter chart of accounts for a small service business, loosely following
 * the QuickBooks Online defaults so exports map cleanly.
 */
final class DefaultChartOfAccounts
{
    /**
     * @return list<array{code: string, name: string, type: AccountType}>
     */
    public static function accounts(): array
    {
        return [
            ['code' => '1000', 'name' => 'Business Checking', 'type' => AccountType::Asset],
            ['code' => '1010', 'name' => 'Business Savings', 'type' => AccountType::Asset],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset],
            ['code' => '1500', 'name' => 'Equipment', 'type' => AccountType::Asset],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => AccountType::Liability],
            ['code' => '2100', 'name' => 'Business Credit Card', 'type' => AccountType::Liability],
            ['code' => '2200', 'name' => 'Sales Tax Payable', 'type' => AccountType::Liability],
            ['code' => '3000', 'name' => "Owner's Equity", 'type' => AccountType::Equity],
            ['code' => '3100', 'name' => "Owner's Draw", 'type' => AccountType::Equity],
            ['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income],
            ['code' => '4100', 'name' => 'Service Revenue', 'type' => AccountType::Income],
            ['code' => '4900', 'name' => 'Interest Income', 'type' => AccountType::Income],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => AccountType::Expense],
            ['code' => '6000', 'name' => 'Advertising & Marketing', 'type' => AccountType::Expense],
            ['code' => '6100', 'name' => 'Bank Fees & Service Charges', 'type' => AccountType::Expense],
            ['code' => '6200', 'name' => 'Contractors', 'type' => AccountType::Expense],
            ['code' => '6300', 'name' => 'Insurance', 'type' => AccountType::Expense],
            ['code' => '6400', 'name' => 'Meals', 'type' => AccountType::Expense],
            ['code' => '6500', 'name' => 'Office Supplies & Software', 'type' => AccountType::Expense],
            ['code' => '6600', 'name' => 'Professional Fees', 'type' => AccountType::Expense],
            ['code' => '6700', 'name' => 'Rent & Lease', 'type' => AccountType::Expense],
            ['code' => '6800', 'name' => 'Travel', 'type' => AccountType::Expense],
            ['code' => '6900', 'name' => 'Utilities', 'type' => AccountType::Expense],
            ['code' => '6950', 'name' => 'Vehicle Expenses', 'type' => AccountType::Expense],
            ['code' => '9999', 'name' => 'Uncategorized Expense', 'type' => AccountType::Expense],
        ];
    }
}
