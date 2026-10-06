<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\BankAccount;
use App\Models\Import;
use App\Models\ImportProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'client_id' => fn (array $attributes) => BankAccount::query()->whereKey($attributes['bank_account_id'])->value('client_id'),
            'import_profile_id' => ImportProfile::factory(),
            'original_filename' => 'statement.csv',
            'stored_path' => 'imports/statement.csv',
            'file_hash' => hash('sha256', fake()->uuid()),
            'status' => ImportStatus::Pending,
        ];
    }
}
