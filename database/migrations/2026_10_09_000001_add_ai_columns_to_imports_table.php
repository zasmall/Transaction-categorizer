<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedInteger('ai_suggested_rows')->default(0)->after('categorized_rows');
            $table->string('ai_model')->nullable()->after('ai_suggested_rows');
            $table->unsignedInteger('ai_input_tokens')->default(0)->after('ai_model');
            $table->unsignedInteger('ai_output_tokens')->default(0)->after('ai_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['ai_suggested_rows', 'ai_model', 'ai_input_tokens', 'ai_output_tokens']);
        });
    }
};
