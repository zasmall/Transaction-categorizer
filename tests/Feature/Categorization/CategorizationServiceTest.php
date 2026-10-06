<?php

use App\Enums\CategorizationMethod;
use App\Enums\CategorizationStatus;
use App\Enums\RuleDirection;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Categorization;
use App\Models\CategorizationRule;
use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->bankAccount = BankAccount::factory()->for($this->client)->create();
    $this->meals = Account::factory()->for($this->client)->create(['code' => '6400', 'name' => 'Meals']);
    $this->software = Account::factory()->for($this->client)->create(['code' => '6500', 'name' => 'Software']);
    $this->service = app(CategorizationService::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function clientTransaction(array $attributes = []): Transaction
{
    return Transaction::factory()->for(test()->bankAccount)->create([
        'client_id' => test()->client->id,
        'payee_normalized' => 'Blue Bottle Coffee Oakland',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function clientRule(Account $account, array $attributes = []): CategorizationRule
{
    return CategorizationRule::factory()->for(test()->client)->create(['account_id' => $account->id, ...$attributes]);
}

test('rules categorize matching transactions and record why', function () {
    $rule = clientRule($this->meals, ['name' => 'Coffee shops', 'pattern' => 'coffee']);
    $coffee = clientTransaction();
    $other = clientTransaction(['payee_normalized' => 'Adobe Creative Cld']);

    $count = $this->service->applyRules($this->client, Transaction::query());

    expect($count)->toBe(1)
        ->and($coffee->refresh()->account_id)->toBe($this->meals->id)
        ->and($coffee->categorization_status)->toBe(CategorizationStatus::Approved)
        ->and($other->refresh()->categorization_status)->toBe(CategorizationStatus::Uncategorized);

    $categorization = $coffee->currentCategorization;
    expect($categorization->method)->toBe(CategorizationMethod::Rule)
        ->and($categorization->rule_id)->toBe($rule->id)
        ->and($categorization->explanation())->toBe('Rule: Coffee shops');

    expect($rule->refresh()->hits_count)->toBe(1)
        ->and($rule->last_matched_at)->not->toBeNull();
});

test('rules never override a person\'s decision', function () {
    clientRule($this->meals);
    $transaction = clientTransaction();
    $this->service->categorizeManually($transaction, $this->software, User::factory()->create());

    expect($this->service->applyRules($this->client, Transaction::query()))->toBe(0)
        ->and($transaction->refresh()->account_id)->toBe($this->software->id);
});

test('rules replace AI suggestions', function () {
    clientRule($this->meals);
    $transaction = clientTransaction([
        'categorization_status' => CategorizationStatus::Suggested,
        'account_id' => $this->software->id,
    ]);

    $this->service->applyRules($this->client, Transaction::query());

    expect($transaction->refresh()->account_id)->toBe($this->meals->id)
        ->and($transaction->categorization_status)->toBe(CategorizationStatus::Approved);
});

test('inactive rules and rules pointing at inactive accounts are skipped', function () {
    clientRule($this->meals, ['is_active' => false]);
    clientRule($this->software, ['pattern' => 'blue']);
    $this->software->update(['is_active' => false]);
    clientTransaction();

    expect($this->service->applyRules($this->client, Transaction::query()))->toBe(0);
});

test('rules only ever touch their own client\'s transactions', function () {
    clientRule($this->meals);
    $otherClient = Client::factory()->create();
    $foreign = Transaction::factory()->for(BankAccount::factory()->for($otherClient))->create([
        'client_id' => $otherClient->id,
        'payee_normalized' => 'Blue Bottle Coffee',
    ]);

    expect($this->service->applyRules($this->client, Transaction::query()))->toBe(0)
        ->and($foreign->refresh()->categorization_status)->toBe(CategorizationStatus::Uncategorized);
});

test('hit counts add up per rule', function () {
    $coffee = clientRule($this->meals, ['pattern' => 'coffee', 'direction' => RuleDirection::Outflow]);
    $refunds = clientRule($this->software, ['pattern' => 'coffee', 'direction' => RuleDirection::Inflow]);
    clientTransaction();
    clientTransaction();
    clientTransaction(['amount_cents' => 575]);

    expect($this->service->applyRules($this->client, Transaction::query()))->toBe(3)
        ->and($coffee->refresh()->hits_count)->toBe(2)
        ->and($refunds->refresh()->hits_count)->toBe(1);
});

test('history is append-only with exactly one current decision', function () {
    clientRule($this->meals, ['name' => 'Coffee shops']);
    $transaction = clientTransaction();
    $user = User::factory()->create(['name' => 'Pat Bookkeeper']);

    $this->service->applyRules($this->client, Transaction::query());
    $this->service->categorizeManually($transaction, $this->software, $user);

    $history = $transaction->categorizations()->get();
    expect($history)->toHaveCount(2)
        ->and($history->pluck('method')->all())->toBe([CategorizationMethod::Rule, CategorizationMethod::Manual])
        ->and($history->where('is_current', true))->toHaveCount(1)
        ->and($transaction->currentCategorization->explanation())->toBe('Manual: Pat Bookkeeper')
        ->and($transaction->refresh()->account_id)->toBe($this->software->id);
});

test('history survives deleting the rule', function () {
    $rule = clientRule($this->meals, ['name' => 'Coffee shops']);
    $transaction = clientTransaction();
    $this->service->applyRules($this->client, Transaction::query());

    $rule->delete();

    expect($transaction->currentCategorization->rule_id)->toBeNull()
        ->and($transaction->currentCategorization->explanation())->toBe('Rule: Coffee shops');
});

test('a transaction cannot be categorized to another client\'s account', function () {
    $foreignAccount = Account::factory()->create();

    expect(fn () => $this->service->categorizeManually(clientTransaction(), $foreignAccount, User::factory()->create()))
        ->toThrow(InvalidArgumentException::class);

    expect(Categorization::count())->toBe(0);
});
