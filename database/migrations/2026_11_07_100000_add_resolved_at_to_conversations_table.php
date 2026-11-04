<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owner marks a studio thread resolved (Phase 7D); a new customer message, or Reopen, makes it open again.
        // NULL means open
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('needs_owner_at');
            $table->index(['kind', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['kind', 'resolved_at']);
            $table->dropColumn('resolved_at');
        });
    }
};
