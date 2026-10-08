<?php

namespace App\Jobs\Relay;

use App\Models\Categorization;
use App\Relay\RelayPublisher;
use App\Relay\TransactionCategorizedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publishes one approved categorization. Dispatched after the categorization commits.
 */
class PublishTransactionCategorized implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly int $categorizationId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ["categorization:{$this->categorizationId}"];
    }

    public function handle(RelayPublisher $publisher): void
    {
        $categorization = Categorization::with(['transaction', 'account'])->find($this->categorizationId);

        // Superseded by a later decision, which has its own job; or the transaction is gone.
        if ($categorization === null || ! $categorization->is_current) {
            return;
        }

        $publisher->publish(
            TransactionCategorizedEvent::TYPE,
            TransactionCategorizedEvent::payload($categorization),
            TransactionCategorizedEvent::idempotencyKey($categorization),
        );
    }
}
