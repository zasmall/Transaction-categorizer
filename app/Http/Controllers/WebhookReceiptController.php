<?php

namespace App\Http\Controllers;

use App\Models\WebhookReceipt;
use Inertia\Inertia;
use Inertia\Response;

class WebhookReceiptController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('webhooks/Receipts', [
            'receipts' => WebhookReceipt::query()
                ->latest('received_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (WebhookReceipt $receipt) => [
                    'id' => $receipt->id,
                    'event_id' => $receipt->event_id,
                    'event_type' => $receipt->event_type,
                    'delivery_id' => $receipt->delivery_id,
                    // Pretty-print the stored JSON decoded as objects; the array
                    // cast would turn {} into [].
                    'payload' => json_encode(json_decode($receipt->getRawOriginal('payload')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'received_at' => $receipt->received_at->toIso8601String(),
                ]),
        ]);
    }
}
