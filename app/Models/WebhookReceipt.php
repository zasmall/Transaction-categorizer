<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A webhook received from Webhook Relay. Not client-owned: it records what
 * arrived, before anything acts on it.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string|null $delivery_id
 * @property array<string, mixed> $payload The event's "data"
 * @property Carbon $received_at
 */
#[Fillable(['event_id', 'event_type', 'delivery_id', 'payload', 'received_at'])]
class WebhookReceipt extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
