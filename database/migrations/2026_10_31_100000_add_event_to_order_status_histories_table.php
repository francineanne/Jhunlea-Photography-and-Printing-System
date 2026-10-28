<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            // Null for a status change. Otherwise what happened without a status change (App\Enums\OrderEvent):
            // the shop asked for information, the customer answered, or the confirmed price changed
            $table->string('event', 32)->nullable()->after('to_status');
        });
    }

    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
};
