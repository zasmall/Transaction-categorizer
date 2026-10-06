<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_profiles', function (Blueprint $table) {
            $table->id();
            // Null client_id means a system default profile available to every client.
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('parser_key');
            $table->json('column_map');
            $table->string('date_format');
            $table->string('amount_convention');
            $table->char('delimiter', 1)->default(',');
            $table->boolean('has_header')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_profiles');
    }
};
