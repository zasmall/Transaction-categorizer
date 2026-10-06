<?php

use App\Enums\CategorizationMethod;
use App\Enums\CategorizationStatus;
use App\Models\BankAccount;
use App\Models\CategorizationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClientOnboardingService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Pat Bookkeeper']);
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
    $this->meals = $this->client->accounts()->where('code', '6400')->sole();
    $this->bankAccount = BankAccount::factory()->for($this->client)->create(['name' => 'Checking']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function txn(array $attributes = []): Transaction
{
    return Transaction::factory()->for(test()->bankAccount)->create([
        'client_id' => test()->client->id,
        'payee_normalized' => 'Blue Bottle Coffee Oakland',
        'amount_cents' => -575,
        ...$attributes,
    ]);
}

test('transactions are listed newest first with counts per status', function () {
    txn(['posted_on' => '2026-01-05']);
    txn(['posted_on' => '2026-01-09', 'payee_normalized' => 'Staples Berkeley']);
    txn(['categorization_status' => CategorizationStatus::Approved, 'account_id' => $this->meals->id]);

    $this->actingAs($this->user)
        ->get(route('clients.transactions.index', [$this->client, 'status' => 'uncategorized']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/Index')
            ->where('status', 'uncategorized')
            ->has('transactions.data', 2)
            ->where('transactions.data.0.payee', 'Staples Berkeley')
            ->where('transactions.data.0.amount', '-$5.75')
            ->where('transactions.data.0.bank_account', 'Checking')
            ->where('counts.uncategorized', 2)
            ->where('counts.approved', 1),
        );
});

test('an unknown status filter is rejected', function () {
    $this->actingAs($this->user)
        ->get(route('clients.transactions.index', [$this->client, 'status' => 'bogus']))
        ->assertSessionHasErrors('status');
});

test('categorizing by hand records who did it and suggests a rule', function () {
    $transaction = txn();
    txn();
    txn(['payee_normalized' => 'Staples Berkeley']);

    $this->actingAs($this->user)
        ->from(route('clients.transactions.index', $this->client))
        ->patch(route('clients.transactions.update', [$this->client, $transaction]), ['account_id' => $this->meals->id])
        ->assertRedirect(route('clients.transactions.index', $this->client))
        ->assertInertiaFlash('toast.message', 'Categorized as Meals.')
        ->assertInertiaFlash('ruleSuggestion', [
            'payee' => 'Blue Bottle Coffee Oakland',
            'account_id' => $this->meals->id,
            'account' => 'Meals',
            'similar' => 1,
        ]);

    expect($transaction->refresh()->categorization_status)->toBe(CategorizationStatus::Approved)
        ->and($transaction->currentCategorization->method)->toBe(CategorizationMethod::Manual)
        ->and($transaction->currentCategorization->explanation())->toBe('Manual: Pat Bookkeeper');

    $this->actingAs($this->user)
        ->get(route('clients.transactions.index', [$this->client, 'status' => 'approved']))
        ->assertInertia(fn (Assert $page) => $page->where('transactions.data.0.explanation', 'Manual: Pat Bookkeeper'));
});

test('no rule is suggested when an existing rule already does the same thing', function () {
    CategorizationRule::factory()->for($this->client)->create(['account_id' => $this->meals->id, 'pattern' => 'blue bottle']);

    $this->actingAs($this->user)
        ->patch(route('clients.transactions.update', [$this->client, txn()]), ['account_id' => $this->meals->id])
        ->assertInertiaFlashMissing('ruleSuggestion');
});

test('a rule is suggested when the person overrides what a rule would do', function () {
    CategorizationRule::factory()->for($this->client)->create(['account_id' => $this->meals->id, 'pattern' => 'blue bottle']);
    $software = $this->client->accounts()->where('code', '6500')->sole();

    $this->actingAs($this->user)
        ->patch(route('clients.transactions.update', [$this->client, txn()]), ['account_id' => $software->id])
        ->assertInertiaFlash('ruleSuggestion.account_id', $software->id);
});

test('similar-payee counting treats wildcards literally', function () {
    $transaction = txn(['payee_normalized' => '100% Juice']);
    txn(['payee_normalized' => '100X Juice']);

    $this->actingAs($this->user)
        ->patch(route('clients.transactions.update', [$this->client, $transaction]), ['account_id' => $this->meals->id])
        ->assertInertiaFlash('ruleSuggestion.similar', 0);
});

test('transactions can only be categorized to the client\'s own accounts', function () {
    $other = app(ClientOnboardingService::class)->create($this->user, 'Other Client');

    $this->actingAs($this->user)
        ->patch(route('clients.transactions.update', [$this->client, txn()]), ['account_id' => $other->accounts()->value('id')])
        ->assertSessionHasErrors('account_id');
});

test('outsiders cannot see or categorize transactions', function () {
    $outsider = User::factory()->create();
    $transaction = txn();

    $this->actingAs($outsider)->get(route('clients.transactions.index', $this->client))->assertForbidden();
    $this->actingAs($outsider)
        ->patch(route('clients.transactions.update', [$this->client, $transaction]), ['account_id' => $this->meals->id])
        ->assertForbidden();

    expect($transaction->refresh()->categorization_status)->toBe(CategorizationStatus::Uncategorized);
});

test('another client\'s transaction is not reachable through this client', function () {
    $other = app(ClientOnboardingService::class)->create($this->user, 'Other Client');
    $foreign = Transaction::factory()->for(BankAccount::factory()->for($other))->create(['client_id' => $other->id]);

    $this->actingAs($this->user)
        ->patch(route('clients.transactions.update', [$this->client, $foreign]), ['account_id' => $this->meals->id])
        ->assertNotFound();
});
