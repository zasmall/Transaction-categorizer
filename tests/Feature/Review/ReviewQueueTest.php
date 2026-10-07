<?php

use App\Enums\CategorizationMethod;
use App\Enums\CategorizationStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\ClientOnboardingService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
    $this->bankAccount = BankAccount::factory()->for($this->client)->create();
    $this->meals = $this->client->accounts()->where('code', '6400')->sole();
    $this->service = app(CategorizationService::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function queued(string $payee, ?int $confidence = null, array $attributes = []): Transaction
{
    $transaction = Transaction::factory()->for(test()->bankAccount)->create([
        'client_id' => test()->client->id,
        'payee_normalized' => $payee,
        ...$attributes,
    ]);

    if ($confidence !== null) {
        test()->service->suggest($transaction, test()->meals, $confidence, 'Because', 'claude-opus-5-5');
    }

    return $transaction->refresh();
}

test('the queue shows what needs a person, least certain first', function () {
    queued('Sure Thing', 95);
    queued('Unknown Payee');
    queued('Coin Flip', 40);
    queued('Done Already', attributes: ['categorization_status' => CategorizationStatus::Approved, 'account_id' => $this->meals->id]);

    $this->actingAs($this->user)
        ->get(route('clients.review.index', $this->client))
        ->assertInertia(fn (Assert $page) => $page
            ->component('review/Index')
            ->has('queue.data', 3)
            ->where('queue.data.0.payee', 'Unknown Payee')
            ->where('queue.data.0.confidence', null)
            ->where('queue.data.1.payee', 'Coin Flip')
            ->where('queue.data.1.confidence', 40)
            ->where('queue.data.2.payee', 'Sure Thing'),
        );
});

test('selected suggestions can be approved in bulk', function () {
    $first = queued('Blue Bottle', 70);
    $second = queued('Peets', 90);
    $uncategorized = queued('Mystery');

    $this->actingAs($this->user)
        ->post(route('clients.review.approve', $this->client), ['transaction_ids' => [$first->id, $second->id, $uncategorized->id]])
        ->assertInertiaFlash('toast.message', 'Approved 2 suggestions. Skipped 1 that wasn\'t a suggestion.');

    expect($first->refresh()->categorization_status)->toBe(CategorizationStatus::Approved)
        ->and($first->currentCategorization->method)->toBe(CategorizationMethod::Manual)
        ->and($first->currentCategorization->user_id)->toBe($this->user->id)
        ->and($second->refresh()->categorization_status)->toBe(CategorizationStatus::Approved)
        ->and($uncategorized->refresh()->categorization_status)->toBe(CategorizationStatus::Uncategorized);
});

test('bulk approve ignores other clients\' transactions', function () {
    $other = app(ClientOnboardingService::class)->create($this->user, 'Other Client');
    $foreign = Transaction::factory()->for(BankAccount::factory()->for($other))->create(['client_id' => $other->id]);
    app(CategorizationService::class)->suggest($foreign, $other->accounts()->first(), 90, 'x', 'claude-opus-5-5');

    $this->actingAs($this->user)
        ->post(route('clients.review.approve', $this->client), ['transaction_ids' => [$foreign->id]])
        ->assertInertiaFlash('toast.message', 'Nothing to approve: none of those are suggestions any more.');

    expect($foreign->refresh()->categorization_status)->toBe(CategorizationStatus::Suggested);
});

test('bulk approve validates its input', function (mixed $ids) {
    $this->actingAs($this->user)
        ->post(route('clients.review.approve', $this->client), ['transaction_ids' => $ids])
        ->assertSessionHasErrors('transaction_ids');
})->with([
    'missing' => [null],
    'empty' => [[]],
    'too many' => [range(1, 201)],
]);

test('outsiders cannot see or approve the queue', function () {
    $outsider = User::factory()->create();
    $transaction = queued('Blue Bottle', 70);

    $this->actingAs($outsider)->get(route('clients.review.index', $this->client))->assertForbidden();
    $this->actingAs($outsider)
        ->post(route('clients.review.approve', $this->client), ['transaction_ids' => [$transaction->id]])
        ->assertForbidden();
});
