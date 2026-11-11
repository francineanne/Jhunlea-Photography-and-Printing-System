<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The income report's remembered view (Phase 8C): daily, weekly or monthly, kept per owner so Reports opens the
        // way they like it. Only the owner's own report reads it. Every existing row gets daily.
        Schema::table('users', function (Blueprint $table) {
            $table->string('report_view', 10)->default('daily')->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('report_view');
        });
    }
};
