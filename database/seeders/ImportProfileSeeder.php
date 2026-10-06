<?php

namespace Database\Seeders;

use App\Enums\AmountConvention;
use App\Models\ImportProfile;
use Illuminate\Database\Seeder;

/**
 * System default import profiles for common bank CSV layouts. Safe to re-run.
 */
class ImportProfileSeeder extends Seeder
{
    public const CHASE_CHECKING = 'Chase Checking';

    public const CAPITAL_ONE_CARD = 'Capital One Credit Card';

    public const AMEX_CARD = 'American Express Card';

    public function run(): void
    {
        $profiles = [
            [
                'name' => self::CHASE_CHECKING,
                'column_map' => ['date' => 'Posting Date', 'description' => 'Description', 'amount' => 'Amount'],
                'date_format' => 'm/d/Y',
                'amount_convention' => AmountConvention::Signed,
            ],
            [
                'name' => self::CAPITAL_ONE_CARD,
                'column_map' => ['date' => 'Posted Date', 'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit'],
                'date_format' => 'Y-m-d',
                'amount_convention' => AmountConvention::DebitCreditColumns,
            ],
            [
                'name' => self::AMEX_CARD,
                'column_map' => ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount'],
                'date_format' => 'm/d/Y',
                'amount_convention' => AmountConvention::Inverted,
            ],
        ];

        foreach ($profiles as $profile) {
            ImportProfile::query()->updateOrCreate(
                ['client_id' => null, 'name' => $profile['name']],
                [...$profile, 'parser_key' => 'csv', 'delimiter' => ',', 'has_header' => true],
            );
        }
    }
}
