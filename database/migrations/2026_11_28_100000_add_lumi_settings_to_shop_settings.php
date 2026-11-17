<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10E: the owner's Lumi switch and the text customers see when Lumi cannot answer (Settings, Lumi assistant).
 * Two nullable columns: NULL means "on" and "the starting text", so every existing row keeps working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_settings', 'lumi_enabled')) {
                $table->boolean('lumi_enabled')->nullable();
            }
            if (! Schema::hasColumn('shop_settings', 'lumi_fallback_text')) {
                $table->string('lumi_fallback_text', 300)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            foreach (['lumi_enabled', 'lumi_fallback_text'] as $column) {
                if (Schema::hasColumn('shop_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
