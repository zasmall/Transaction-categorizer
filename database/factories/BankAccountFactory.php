<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => 'Operating Checking',
            'institution' => fake()->randomElement(['Chase', 'Wells Fargo', 'Capital One']),
            'last4' => fake()->numerify('####'),
            // The ledger account must belong to the same client as the bank account.
            'ledger_account_id' => fn (array $attributes) => Account::factory()
                ->ofType(AccountType::Asset)
                ->state(['client_id' => $attributes['client_id']]),
            'import_profile_id' => null,
        ];
    }
}
