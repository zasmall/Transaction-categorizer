<?php

namespace App\Http\Controllers;

use App\Models\WebhookReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Receives Webhook Relay deliveries. The relay-signature middleware has
 * already verified the raw body by the time this runs.
 */
class RelayWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $envelope = Validator::make($request->json()->all(), [
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'data' => ['present', 'array'],
        ])->validate();

        // Delivery is at least once: a redelivered event id inserts nothing
        // and still gets a 200, so the relay stops retrying.
        $inserted = WebhookReceipt::query()->insertOrIgnore([
            'event_id' => $envelope['id'],
            'event_type' => $envelope['type'],
            'delivery_id' => $request->header('X-Relay-Delivery-Id'),
            // Re-encode "data" from the raw body as objects, so {} stays {}.
            'payload' => json_encode(json_decode($request->getContent())->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'received_at' => now(),
        ]);

        return response()->json(['received' => true, 'duplicate' => $inserted === 0]);
    }
}
