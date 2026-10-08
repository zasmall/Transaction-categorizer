<?php

use App\Http\Controllers\RelayWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook Relay deliveries. Stateless and CSRF-free; the signature is the auth.
Route::post('webhooks/relay', RelayWebhookController::class)
    ->middleware('relay.signature')
    ->name('webhooks.relay');
