<?php

use App\Categorization\LearnedRuleSuggestions;
use App\Enums\CategorizationStatus;
use App\Enums\RuleOperator;
use App\Enums\RuleSource;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\CategorizationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\ClientOnboardingService;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
    $this->bankAccount = BankAccount::factory()->for($this->client)->create();
    $this->meals = $this->client->accounts()->where('code', '6400')->sole();
    $this->software = $this->client->accounts()->where('code', '6500')->sole();
});

function approvedTimes(int $times, string $payee, Account $account): void
{
    foreach (range(1, $times) as $i) {
        $transaction = Transaction::factory()->for(test()->bankAccount)->create([
            'client_id' => test()->client->id,
            'payee_normalized' => $payee,
        ]);
        app(CategorizationService::class)->categorizeManually($transaction, $account, test()->user);
    }
}

function learned(): Collection
{
    return app(LearnedRuleSuggestions::class)->for(test()->client);
}

test('a payee approved to the same account three times becomes a suggestion', function () {
    approvedTimes(3, 'Blue Bottle Coffee', $this->meals);
    approvedTimes(2, 'Adobe', $this->software);

    expect(learned()->all())->toBe([[
        'payee' => 'Blue Bottle Coffee',
        'account_id' => $this->meals->id,
        'account' => '6400 · Meals',
        'approvals' => 3,
    ]]);
});

test('payee matching ignores case', function () {
    approvedTimes(2, 'Blue Bottle Coffee', $this->meals);
    approvedTimes(1, 'BLUE BOTTLE COFFEE', $this->meals);

    expect(learned())->toHaveCount(1);
});

test('a payee approved to different accounts is ambiguous and not suggested', function () {
    approvedTimes(3, 'Amazon', $this->software);
    approvedTimes(1, 'Amazon', $this->meals);

    expect(learned())->toBeEmpty();
});

test('no suggestion when a rule already handles the payee', function () {
    approvedTimes(3, 'Blue Bottle Coffee', $this->meals);
    CategorizationRule::factory()->for($this->client)->create(['account_id' => $this->meals->id, 'pattern' => 'blue bottle']);

    expect(learned())->toBeEmpty();
});

test('a learned suggestion becomes an exact-match rule and is applied right away', function () {
    approvedTimes(3, 'Blue Bottle Coffee', $this->meals);
    $waiting = Transaction::factory()->for($this->bankAccount)->create([
        'client_id' => $this->client->id,
        'payee_normalized' => 'Blue Bottle Coffee',
    ]);

    $this->actingAs($this->user)
        ->get(route('clients.rules.index', $this->client))
        ->assertInertia(fn (Assert $page) => $page->has('learnedRules', 1)->where('learnedRules.0.approvals', 3));

    $this->actingAs($this->user)
        ->post(route('clients.rules.learned', $this->client), ['payee' => 'blue bottle coffee', 'account_id' => $this->meals->id])
        ->assertInertiaFlash('toast.message', 'Rule created from Blue Bottle Coffee. It categorized 1 more transaction.');

    $rule = CategorizationRule::sole();
    expect($rule->source)->toBe(RuleSource::Learned)
        ->and($rule->operator)->toBe(RuleOperator::Equals)
        ->and($rule->pattern)->toBe('Blue Bottle Coffee')
        ->and($waiting->refresh()->categorization_status)->toBe(CategorizationStatus::Approved)
        ->and(learned())->toBeEmpty();
});

test('only genuine suggestions can be turned into rules', function () {
    approvedTimes(2, 'Blue Bottle Coffee', $this->meals);

    $this->actingAs($this->user)
        ->post(route('clients.rules.learned', $this->client), ['payee' => 'Blue Bottle Coffee', 'account_id' => $this->meals->id])
        ->assertStatus(422);

    expect(CategorizationRule::count())->toBe(0);
});
