<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorization_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Lower runs first; the first matching rule wins.
            $table->unsignedInteger('priority')->default(100);
            $table->string('match_field');
            $table->string('operator');
            $table->string('pattern');
            $table->string('direction')->default('any');
            // Bounds on the absolute amount, so "between $10 and $50" works for money in or out.
            $table->unsignedBigInteger('amount_min_cents')->nullable();
            $table->unsignedBigInteger('amount_max_cents')->nullable();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('manual');
            $table->unsignedInteger('hits_count')->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['client_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorization_rules');
    }
};
