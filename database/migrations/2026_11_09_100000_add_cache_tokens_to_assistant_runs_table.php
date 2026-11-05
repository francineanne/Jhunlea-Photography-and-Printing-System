<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Claude's prompt cache usage (Phase 7E-2a): tokens read from and written to the cache, counted apart from
        // input_tokens. NULL: not reported (Gemini, an answer by code, a failed call)
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->unsignedInteger('cache_read_tokens')->nullable()->after('output_tokens');
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('cache_read_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->dropColumn(['cache_read_tokens', 'cache_write_tokens']);
        });
    }
};
