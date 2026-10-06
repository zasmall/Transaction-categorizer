<?php

namespace Database\Factories;

use App\Enums\CategorizationStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payee = fake()->company();

        return [
            'bank_account_id' => BankAccount::factory(),
            'client_id' => fn (array $attributes) => BankAccount::query()->whereKey($attributes['bank_account_id'])->value('client_id'),
            'posted_on' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'amount_cents' => -fake()->numberBetween(100, 50_000),
            'description_raw' => strtoupper($payee).' '.fake()->numerify('######'),
            'payee_normalized' => $payee,
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'categorization_status' => CategorizationStatus::Uncategorized,
        ];
    }
}
