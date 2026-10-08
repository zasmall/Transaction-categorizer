<?php

use App\Models\User;
use App\Models\WebhookReceipt;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Zasmall\RelaySignature\Signer;

beforeEach(function () {
    config(['relay-signature.secrets' => ['whsec_categorizer']]);
});

function relayEnvelope(string $eventId = '01m4cbsk4n72yn26ct15qzw9vq', array|object $data = ['invoice_id' => 'inv_1']): string
{
    return json_encode([
        'id' => $eventId,
        'type' => 'invoice.paid',
        'created_at' => '2026-10-08T12:00:00Z',
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * Posts a raw body the way Webhook Relay does.
 *
 * @param  list<string>  $secrets
 */
function relayDelivery(string $body, array $secrets = ['whsec_categorizer'], ?int $signedAt = null): TestResponse
{
    return test()->call('POST', '/api/webhooks/relay', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_RELAY_DELIVERY_ID' => '01m4cbsk8xqaq88efb6enjmhn4',
        'HTTP_X_RELAY_SIGNATURE' => Signer::header($body, $secrets, $signedAt ?? time()),
    ], $body);
}

it('records a verified delivery', function () {
    relayDelivery(relayEnvelope())
        ->assertOk()
        ->assertExactJson(['received' => true, 'duplicate' => false]);

    expect(WebhookReceipt::sole())
        ->event_id->toBe('01m4cbsk4n72yn26ct15qzw9vq')
        ->event_type->toBe('invoice.paid')
        ->delivery_id->toBe('01m4cbsk8xqaq88efb6enjmhn4')
        ->payload->toBe(['invoice_id' => 'inv_1']);
});

it('accepts a redelivery once and still answers 200', function () {
    relayDelivery(relayEnvelope())->assertOk();

    relayDelivery(relayEnvelope())
        ->assertOk()
        ->assertExactJson(['received' => true, 'duplicate' => true]);

    expect(WebhookReceipt::count())->toBe(1);
});

it('accepts deliveries signed during a relay secret rotation', function () {
    relayDelivery(relayEnvelope(), ['whsec_new_on_relay', 'whsec_categorizer'])->assertOk();
});

it('accepts either secret while the receiver rotates', function () {
    config(['relay-signature.secrets' => ['whsec_next', 'whsec_categorizer']]);

    relayDelivery(relayEnvelope())->assertOk();
});

it('rejects bad signatures without recording anything', function (array $secrets, int $offset) {
    relayDelivery(relayEnvelope(), $secrets, time() + $offset)->assertUnauthorized();

    expect(WebhookReceipt::count())->toBe(0);
})->with([
    'wrong secret' => [['whsec_attacker'], 0],
    'replayed capture' => [['whsec_categorizer'], -600],
]);

it('rejects unsigned requests', function () {
    $this->postJson('/api/webhooks/relay', json_decode(relayEnvelope(), true))->assertBadRequest();

    expect(WebhookReceipt::count())->toBe(0);
});

it('rejects a signed body that is not a relay envelope', function () {
    relayDelivery('{"hello":"world"}')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id', 'type', 'data']);
});

it('keeps empty objects in the payload', function () {
    relayDelivery(relayEnvelope(data: (object) ['meta' => (object) []]))->assertOk();

    expect(WebhookReceipt::query()->toBase()->value('payload'))->toBe('{"meta":{}}');

    $this->actingAs(User::factory()->create())
        ->get(route('webhooks.index'))
        ->assertInertia(fn (Assert $page) => $page->where('receipts.0.payload', "{\n    \"meta\": {}\n}"));
});

it('lists receipts for signed-in users', function () {
    relayDelivery(relayEnvelope())->assertOk();

    $this->get(route('webhooks.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('webhooks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('webhooks/Receipts')
            ->has('receipts', 1)
            ->where('receipts.0.event_type', 'invoice.paid'));
});
