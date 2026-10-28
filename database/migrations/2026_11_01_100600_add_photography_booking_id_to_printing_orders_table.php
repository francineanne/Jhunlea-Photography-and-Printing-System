<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A printing order the customer starts from a completed photoshoot. No screen uses it yet
        Schema::table('printing_orders', function (Blueprint $table) {
            $table->foreignId('photography_booking_id')->nullable()->after('printing_service_id')->constrained('photography_bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('printing_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('photography_booking_id');
        });
    }
};
