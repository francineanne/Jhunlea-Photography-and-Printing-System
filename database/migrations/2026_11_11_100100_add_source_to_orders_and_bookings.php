<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an order or booking came from (Phase 8A): `online` (the customer's own request; every existing row,
        // through the default) or `walk_in` (made by the owner at the counter). counter_token is the one-time token of
        // the counter form: unique, so a double click or a retry of the same form never saves a second record. Online
        // rows leave it NULL (any number of NULLs is allowed in a unique index).
        foreach (['printing_orders', 'photography_bookings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('source', 10)->default('online')->index();
                $table->string('counter_token', 36)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (['printing_orders', 'photography_bookings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['counter_token']);
                $table->dropIndex(['source']);
                $table->dropColumn(['source', 'counter_token']);
            });
        }
    }
};
