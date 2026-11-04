<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The provider the chain started with when the backup took the call (Phase 7E-1). NULL: the first provider
        // answered, or nothing was asked
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->string('fallback_from', 20)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->dropColumn('fallback_from');
        });
    }
};
