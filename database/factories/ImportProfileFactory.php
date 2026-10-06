<?php

namespace Database\Factories;

use App\Enums\AmountConvention;
use App\Models\ImportProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportProfile>
 */
class ImportProfileFactory extends Factory
{
    /**
     * Define the model's default state. Profiles are system defaults unless a client is given.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => null,
            'name' => 'Generic signed CSV',
            'parser_key' => 'csv',
            'column_map' => ['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount'],
            'date_format' => 'm/d/Y',
            'amount_convention' => AmountConvention::Signed,
            'delimiter' => ',',
            'has_header' => true,
        ];
    }
}
