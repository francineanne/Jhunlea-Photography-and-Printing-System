<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owner's Ask Lumi (Phase 7E-3a): the names of the read-only tools a question used, comma separated.
        // Tool names only, never parameters or results
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->string('tools', 200)->nullable()->after('intent');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_runs', function (Blueprint $table) {
            $table->dropColumn('tools');
        });
    }
};
