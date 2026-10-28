<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reference_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photography_booking_id')->constrained('photography_bookings')->cascadeOnDelete();
            // The name the customer's file had, for display only. The file on disk uses stored_name
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('path', 500);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('photography_booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reference_images');
    }
};
