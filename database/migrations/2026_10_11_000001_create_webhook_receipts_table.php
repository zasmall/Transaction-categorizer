<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            // Webhook Relay delivers at least once; the unique event id makes
            // a redelivery a no-op.
            $table->string('event_id')->unique();
            $table->string('event_type')->index();
            $table->string('delivery_id')->nullable();
            $table->json('payload');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};
