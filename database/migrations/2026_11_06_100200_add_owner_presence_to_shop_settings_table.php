<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether the owner counts as away in the studio chat (Phase 7B): "available", "away", or "auto" (away after a
        // while without activity, see config chat.presence). Written only by OwnerPresence
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('owner_presence', 10)->default('auto');
            $table->timestamp('owner_last_active_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['owner_presence', 'owner_last_active_at']);
        });
    }
};
