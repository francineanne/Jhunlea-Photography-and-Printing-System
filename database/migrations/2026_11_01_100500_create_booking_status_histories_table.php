<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same shape as order_status_histories
        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photography_booking_id')->constrained('photography_bookings')->cascadeOnDelete();
            // Null on the first row, when the booking is requested
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            // What kind of step it was (App\Enums\BookingEvent); null for a plain status change
            $table->string('event', 32)->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['photography_booking_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
    }
};
