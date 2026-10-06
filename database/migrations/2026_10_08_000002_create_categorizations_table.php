<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only history of every categorization decision. Rows are never edited
     * except to clear is_current when a newer decision replaces them.
     */
    public function up(): void
    {
        Schema::create('categorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->foreignId('rule_id')->nullable()->constrained('categorization_rules')->nullOnDelete();
            // Kept even if the rule is later deleted, so history still reads well.
            $table->string('rule_name')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('ai_reason')->nullable();
            $table->string('model')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index(['transaction_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorizations');
    }
};
