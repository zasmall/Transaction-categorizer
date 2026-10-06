<?php

use App\Enums\CategorizationStatus;
use App\Enums\ClientRole;
use App\Enums\RuleDirection;
use App\Enums\RuleOperator;
use App\Models\BankAccount;
use App\Models\CategorizationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClientOnboardingService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
    $this->meals = $this->client->accounts()->where('code', '6400')->sole();
    $this->bankAccount = BankAccount::factory()->for($this->client)->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rulePayload(array $overrides = []): array
{
    return [
        'name' => 'Coffee shops',
        'account_id' => test()->meals->id,
        'match_field' => 'payee',
        'operator' => 'contains',
        'pattern' => 'blue bottle',
        'direction' => 'any',
        'amount_min' => '',
        'amount_max' => '',
        'priority' => 10,
        'is_active' => '1',
        ...$overrides,
    ];
}

function uncategorizedCoffee(): Transaction
{
    return Transaction::factory()->for(test()->bankAccount)->create([
        'client_id' => test()->client->id,
        'payee_normalized' => 'Blue Bottle Coffee Oakland',
    ]);
}

test('members can list rules', function () {
    $bookkeeper = User::factory()->create();
    $this->client->users()->attach($bookkeeper, ['role' => ClientRole::Bookkeeper]);
    CategorizationRule::factory()->for($this->client)->create(['account_id' => $this->meals->id, 'name' => 'Coffee shops']);

    $this->actingAs($bookkeeper)
        ->get(route('clients.rules.index', $this->client))
        ->assertInertia(fn (Assert $page) => $page
            ->component('rules/Index')
            ->has('rules', 1)
            ->where('rules.0.name', 'Coffee shops')
            ->where('rules.0.account', '6400 · Meals')
            ->where('rules.0.summary', 'Payee contains "coffee"'),
        );
});

test('the create form can be prefilled from a transaction', function () {
    $this->actingAs($this->user)
        ->get(route('clients.rules.create', [$this->client, 'payee' => 'Blue Bottle Coffee', 'account_id' => $this->meals->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('rules/Create')
            ->where('rule.pattern', 'Blue Bottle Coffee')
            ->where('rule.operator', 'contains')
            ->where('rule.account_id', $this->meals->id)
            ->has('accounts', $this->client->accounts()->count()),
        );
});

test('creating a rule stores amounts as cents and can apply it straight away', function () {
    $coffee = uncategorizedCoffee();

    $this->actingAs($this->user)
        ->post(route('clients.rules.store', $this->client), rulePayload([
            'direction' => 'outflow',
            'amount_min' => '1',
            'amount_max' => '1,000.50',
            'apply_to_existing' => '1',
        ]))
        ->assertRedirect(route('clients.rules.index', $this->client))
        ->assertInertiaFlash('toast.message', 'Rule saved. Categorized 1 existing transaction.');

    $rule = CategorizationRule::sole();
    expect($rule->client_id)->toBe($this->client->id)
        ->and($rule->direction)->toBe(RuleDirection::Outflow)
        ->and($rule->amount_min_cents)->toBe(100)
        ->and($rule->amount_max_cents)->toBe(100050)
        ->and($rule->hits_count)->toBe(1)
        ->and($coffee->refresh()->account_id)->toBe($this->meals->id);
});

test('creating a rule without applying it leaves existing transactions alone', function () {
    $coffee = uncategorizedCoffee();

    $this->actingAs($this->user)
        ->post(route('clients.rules.store', $this->client), rulePayload())
        ->assertInertiaFlash('toast.message', 'Rule saved.');

    expect($coffee->refresh()->categorization_status)->toBe(CategorizationStatus::Uncategorized);
});

test('rules are validated', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('clients.rules.store', $this->client), rulePayload($overrides))
        ->assertSessionHasErrors($field);

    expect(CategorizationRule::count())->toBe(0);
})->with([
    'invalid regex' => [['operator' => 'regex', 'pattern' => '(unclosed'], 'pattern'],
    'missing pattern' => [['pattern' => ''], 'pattern'],
    'bad amount' => [['amount_min' => 'ten'], 'amount_min'],
    'min above max' => [['amount_min' => '50', 'amount_max' => '10'], 'amount_max'],
    'unknown operator' => [['operator' => 'sounds_like'], 'operator'],
    'zero priority' => [['priority' => 0], 'priority'],
]);

test('a valid regex rule is accepted', function () {
    $this->actingAs($this->user)
        ->post(route('clients.rules.store', $this->client), rulePayload(['operator' => 'regex', 'pattern' => '^(blue bottle|peets)']))
        ->assertSessionHasNoErrors();

    expect(CategorizationRule::sole()->operator)->toBe(RuleOperator::Regex);
});

test('rules can only target the client\'s own active accounts', function () {
    $otherClient = app(ClientOnboardingService::class)->create($this->user, 'Other Client');
    $inactive = $this->client->accounts()->where('code', '6500')->sole();
    $inactive->update(['is_active' => false]);

    $this->actingAs($this->user)
        ->post(route('clients.rules.store', $this->client), rulePayload(['account_id' => $otherClient->accounts()->value('id')]))
        ->assertSessionHasErrors('account_id');
    $this->actingAs($this->user)
        ->post(route('clients.rules.store', $this->client), rulePayload(['account_id' => $inactive->id]))
        ->assertSessionHasErrors('account_id');
});

test('rules can be edited, paused and deleted', function () {
    $rule = CategorizationRule::factory()->for($this->client)->create(['account_id' => $this->meals->id]);

    $this->actingAs($this->user)
        ->get(route('clients.rules.edit', [$this->client, $rule]))
        ->assertInertia(fn (Assert $page) => $page->component('rules/Edit')->where('rule.id', $rule->id));

    $this->actingAs($this->user)
        ->put(route('clients.rules.update', [$this->client, $rule]), rulePayload(['name' => 'Cafés', 'is_active' => '0']))
        ->assertRedirect(route('clients.rules.index', $this->client));

    expect($rule->refresh()->name)->toBe('Cafés')
        ->and($rule->is_active)->toBeFalse();

    $this->actingAs($this->user)
        ->delete(route('clients.rules.destroy', [$this->client, $rule]))
        ->assertRedirect(route('clients.rules.index', $this->client));

    expect(CategorizationRule::count())->toBe(0);
});

test('running rules on demand reports how many were categorized', function () {
    CategorizationRule::factory()->for($this->client)->create(['account_id' => $this->meals->id, 'pattern' => 'blue bottle']);
    uncategorizedCoffee();
    uncategorizedCoffee();

    $this->actingAs($this->user)
        ->from(route('clients.rules.index', $this->client))
        ->post(route('clients.rules.apply', $this->client))
        ->assertRedirect(route('clients.rules.index', $this->client))
        ->assertInertiaFlash('toast.message', 'Rules categorized 2 transactions.');
});

test('outsiders cannot see or change rules', function () {
    $outsider = User::factory()->create();
    $rule = CategorizationRule::factory()->for($this->client)->create(['account_id' => $this->meals->id]);

    $this->actingAs($outsider)->get(route('clients.rules.index', $this->client))->assertForbidden();
    $this->actingAs($outsider)->post(route('clients.rules.store', $this->client), rulePayload())->assertForbidden();
    $this->actingAs($outsider)->delete(route('clients.rules.destroy', [$this->client, $rule]))->assertForbidden();

    expect(CategorizationRule::count())->toBe(1);
});

test('a rule from another client is not reachable through this client', function () {
    $otherClient = app(ClientOnboardingService::class)->create($this->user, 'Other Client');
    $foreignRule = CategorizationRule::factory()->for($otherClient)->create(['account_id' => $otherClient->accounts()->value('id')]);

    $this->actingAs($this->user)
        ->get(route('clients.rules.edit', [$this->client, $foreignRule]))
        ->assertNotFound();
});
