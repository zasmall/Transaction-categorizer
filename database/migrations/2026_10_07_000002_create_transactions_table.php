<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_row_id')->nullable()->constrained()->nullOnDelete();
            $table->date('posted_on');
            // Signed integer cents: negative is money out, positive is money in.
            $table->bigInteger('amount_cents');
            $table->string('description_raw');
            $table->string('payee_normalized');
            $table->string('memo')->nullable();
            $table->char('fingerprint', 64);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('categorization_status');
            $table->timestamps();

            $table->unique(['bank_account_id', 'fingerprint']);
            $table->index(['client_id', 'categorization_status']);
            $table->index(['client_id', 'posted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
