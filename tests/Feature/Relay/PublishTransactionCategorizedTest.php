<?php

use App\Enums\CategorizationStatus;
use App\Jobs\Relay\PublishTransactionCategorized;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Categorization;
use App\Models\CategorizationRule;
use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Relay\RelayPublisher;
use App\Relay\TransactionCategorizedEvent;
use App\Services\CategorizationService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.relay_publisher' => [
        'url' => 'https://relay.test',
        'source_token' => 'src_token',
        'currency' => 'USD',
    ]]);
    $this->relayStatus = 202;
    Http::fake(['relay.test/*' => fn () => Http::response(['data' => []], test()->relayStatus)]);

    $this->client = Client::factory()->create();
    $this->user = User::factory()->create();
    $this->bankAccount = BankAccount::factory()->for($this->client)->create();
    $this->meals = Account::factory()->for($this->client)->create(['code' => '6400', 'name' => 'Meals']);
    $this->software = Account::factory()->for($this->client)->create(['code' => '6500', 'name' => 'Software & Subscriptions']);
    $this->service = app(CategorizationService::class);
});

function publishedTransaction(array $attributes = []): Transaction
{
    return Transaction::factory()->for(test()->bankAccount)->create([
        'posted_on' => '2026-10-01',
        'amount_cents' => -12999,
        'description_raw' => 'ADOBE *CREATIVE CLD',
        'payee_normalized' => 'Adobe',
        ...$attributes,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function sentEvents(): array
{
    return Http::recorded()->map(fn (array $pair) => [
        'request' => $pair[0],
        'body' => $pair[0]->data(),
    ])->all();
}

it('publishes an approved categorization with the agreed contract', function () {
    $transaction = publishedTransaction();

    $categorization = $this->service->categorizeManually($transaction, $this->software, $this->user);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($transaction, $categorization) {
        return $request->url() === 'https://relay.test/api/events'
            && $request->hasHeader('Authorization', 'Bearer src_token')
            && $request->hasHeader('Idempotency-Key', "categorization-{$categorization->id}")
            && $request->data() === [
                'type' => 'transaction.categorized',
                'payload' => [
                    'entity_id' => (string) $this->client->id,
                    'transaction' => [
                        'id' => (string) $transaction->id,
                        'account_id' => (string) $this->bankAccount->id,
                        'posted_on' => '2026-10-01',
                        'amount' => '-129.99',
                        'currency' => 'USD',
                        'description' => 'ADOBE *CREATIVE CLD',
                        'vendor' => 'Adobe',
                        'category' => 'Software & Subscriptions',
                        'categorized_at' => $categorization->created_at->toIso8601ZuluString(),
                    ],
                ],
            ];
    });
});

it('publishes rule matches, which are approved', function () {
    publishedTransaction(['payee_normalized' => 'Blue Bottle Coffee']);
    CategorizationRule::factory()->for($this->client)->create([
        'account_id' => $this->meals->id,
        'pattern' => 'blue bottle',
    ]);

    $this->service->applyRules($this->client, Transaction::query());

    Http::assertSentCount(1);
    expect(sentEvents()[0]['body']['payload']['transaction']['category'])->toBe('Meals');
});

it('does not publish AI suggestions, which are not final', function () {
    $transaction = publishedTransaction();

    $this->service->suggest($transaction, $this->software, 92, 'Looks like a software subscription', 'claude-haiku-4-5');

    expect($transaction->fresh()->categorization_status)->toBe(CategorizationStatus::Suggested);
    Http::assertNothingSent();
});

it('publishes nothing when the relay is not configured', function () {
    config(['services.relay_publisher.url' => null]);

    $this->service->categorizeManually(publishedTransaction(), $this->software, $this->user);

    Http::assertNothingSent();
});

it('publishes nothing when the categorization rolls back', function () {
    $transaction = publishedTransaction();

    try {
        DB::transaction(function () use ($transaction) {
            $this->service->categorizeManually($transaction, $this->software, $this->user);
            throw new RuntimeException('something later in the same transaction failed');
        });
    } catch (RuntimeException) {
    }

    expect(Categorization::count())->toBe(0);
    Http::assertNothingSent();
});

it('skips a categorization that a later decision superseded', function () {
    $transaction = publishedTransaction();
    $first = $this->service->categorizeManually($transaction, $this->meals, $this->user);
    $this->service->categorizeManually($transaction, $this->software, $this->user);
    Http::assertSentCount(2);

    (new PublishTransactionCategorized($first->id))->handle(app(RelayPublisher::class));

    Http::assertSentCount(2);
});

it('lets relay failures surface so the queue retries', function () {
    $this->relayStatus = 503;
    config(['services.relay_publisher.url' => null]); // categorize without dispatching
    $categorization = $this->service->categorizeManually(publishedTransaction(), $this->software, $this->user);
    config(['services.relay_publisher.url' => 'https://relay.test']);

    expect(fn () => (new PublishTransactionCategorized($categorization->id))->handle(app(RelayPublisher::class)))
        ->toThrow(RequestException::class);
});

it('formats integer cents as an exact decimal string', function (int $cents, string $decimal) {
    expect(TransactionCategorizedEvent::decimal($cents))->toBe($decimal);
})->with([
    [-12999, '-129.99'],
    [-5, '-0.05'],
    [0, '0.00'],
    [100, '1.00'],
    [123456789, '1234567.89'],
]);

describe('backfill command', function () {
    beforeEach(function () {
        config(['services.relay_publisher.url' => null]); // build history without publishing
        $this->approved = $this->service->categorizeManually(publishedTransaction(), $this->software, $this->user);
        $this->service->suggest(publishedTransaction(['payee_normalized' => 'Mystery']), $this->meals, 40, 'unsure', 'claude-haiku-4-5');
        config(['services.relay_publisher.url' => 'https://relay.test']);
    });

    it('publishes approved categorizations only, with stable idempotency keys', function () {
        $this->artisan('transactions:publish-categorized', ['client' => $this->client->slug])
            ->expectsOutputToContain('Queued 1 transaction.categorized events')
            ->assertSuccessful();
        $this->artisan('transactions:publish-categorized', ['client' => $this->client->slug])->assertSuccessful();

        $keys = collect(sentEvents())->map(fn (array $event) => $event['request']->header('Idempotency-Key')[0]);
        expect($keys->all())->toBe(["categorization-{$this->approved->id}", "categorization-{$this->approved->id}"]);
    });

    it('fails clearly when unconfigured or the client is unknown', function () {
        $this->artisan('transactions:publish-categorized', ['client' => 'nobody'])->assertFailed();

        config(['services.relay_publisher.url' => null]);
        $this->artisan('transactions:publish-categorized', ['client' => $this->client->slug])->assertFailed();

        Http::assertNothingSent();
    });
});
