<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row (id 1) with the owner's schedule settings. Typed columns, so every value is checked by the database too.
        // Until the row exists the defaults in config/booking.php are used. lock_version is only bumped to take the
        // schedule lock while a booking is confirmed (see BookingTransitionService)
        Schema::create('booking_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('buffer_minutes');
            $table->unsignedSmallInteger('min_notice_hours');
            $table->unsignedSmallInteger('max_advance_days');
            $table->unsignedTinyInteger('concurrent_sessions');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_settings');
    }
};
