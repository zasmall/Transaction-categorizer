<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Set when the bookkeeper confirms an export, so nothing is exported twice.
            $table->timestamp('exported_at')->nullable()->after('categorization_status');
            $table->index(['client_id', 'exported_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'exported_at']);
            $table->dropColumn('exported_at');
        });
    }
};
