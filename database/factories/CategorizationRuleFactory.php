<?php

namespace Database\Factories;

use App\Enums\RuleDirection;
use App\Enums\RuleMatchField;
use App\Enums\RuleOperator;
use App\Enums\RuleSource;
use App\Models\Account;
use App\Models\CategorizationRule;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategorizationRule>
 */
class CategorizationRuleFactory extends Factory
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
            'name' => 'Coffee shops',
            'priority' => 100,
            'match_field' => RuleMatchField::Payee,
            'operator' => RuleOperator::Contains,
            'pattern' => 'coffee',
            'direction' => RuleDirection::Any,
            'account_id' => fn (array $attributes) => Account::factory()->state(['client_id' => $attributes['client_id']]),
            'source' => RuleSource::Manual,
            'is_active' => true,
        ];
    }
}
