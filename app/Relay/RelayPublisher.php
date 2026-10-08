<?php

namespace App\Relay;

use Illuminate\Support\Facades\Http;

/**
 * Publishes events to Webhook Relay's ingest API as a registered source.
 *
 * Disabled unless both RELAY_URL and RELAY_SOURCE_TOKEN are set, so the categorizer still
 * runs on its own.
 */
class RelayPublisher
{
    public function enabled(): bool
    {
        return filled(config('services.relay_publisher.url'))
            && filled(config('services.relay_publisher.source_token'));
    }

    /**
     * The relay answers 202 for a new event and 200 for a repeated idempotency key, so retries
     * are safe. Anything else throws, and the queued job retries with backoff.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $type, array $payload, string $idempotencyKey): void
    {
        Http::baseUrl(rtrim((string) config('services.relay_publisher.url'), '/'))
            ->withToken((string) config('services.relay_publisher.source_token'))
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->timeout(10)
            ->post('/api/events', ['type' => $type, 'payload' => $payload])
            ->throw();
    }
}
