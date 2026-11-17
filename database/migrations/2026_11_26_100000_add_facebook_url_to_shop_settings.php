<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10D: the shop's Facebook page address (Settings, Business info), shown on the landing page and in the public
 * footer when set. One nullable column; nothing else changes and no existing row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shop_settings', 'facebook_url')) {
            Schema::table('shop_settings', function (Blueprint $table) {
                $table->string('facebook_url', 255)->nullable()->after('opening_hours');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shop_settings', 'facebook_url')) {
            Schema::table('shop_settings', function (Blueprint $table) {
                $table->dropColumn('facebook_url');
            });
        }
    }
};
