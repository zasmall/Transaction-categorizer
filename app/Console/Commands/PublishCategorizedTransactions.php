<?php

namespace App\Console\Commands;

use App\Enums\CategorizationStatus;
use App\Jobs\Relay\PublishTransactionCategorized;
use App\Models\Categorization;
use App\Models\Client;
use App\Relay\RelayPublisher;
use Illuminate\Console\Command;

/**
 * Backfill: publish a client's already-approved categorizations, e.g. when connecting the relay
 * after the fact. Idempotency keys are per categorization, so re-running publishes nothing new.
 */
class PublishCategorizedTransactions extends Command
{
    protected $signature = 'transactions:publish-categorized {client : The client slug}';

    protected $description = 'Publish transaction.categorized events for a client\'s approved transactions';

    public function handle(RelayPublisher $publisher): int
    {
        if (! $publisher->enabled()) {
            $this->error('Set RELAY_URL and RELAY_SOURCE_TOKEN to publish.');

            return self::FAILURE;
        }

        $client = Client::query()->where('slug', $this->argument('client'))->first();
        if ($client === null) {
            $this->error("No client with slug [{$this->argument('client')}].");

            return self::FAILURE;
        }

        $queued = 0;
        Categorization::query()
            ->where('is_current', true)
            ->whereHas('transaction', fn ($transactions) => $transactions
                ->where('client_id', $client->id)
                ->where('categorization_status', CategorizationStatus::Approved))
            ->select('id')
            ->chunkById(500, function ($categorizations) use (&$queued) {
                foreach ($categorizations as $categorization) {
                    PublishTransactionCategorized::dispatch($categorization->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} transaction.categorized events for {$client->name} (client {$client->id}).");

        return self::SUCCESS;
    }
}
