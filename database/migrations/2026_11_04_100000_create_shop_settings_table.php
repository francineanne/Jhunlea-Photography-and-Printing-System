<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row (id 1) with the owner's business info and upload limits, the same pattern as booking_settings. Every
        // column is nullable: NULL means "not set" (no business detail is shown) or, for a limit, "use the starting
        // default in config". The accepted file TYPES are not stored here: they stay fixed in config (security).
        // Read only through App\Support\ShopSettings
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->string('shop_name', 80)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->unsignedSmallInteger('printing_max_file_mb')->nullable();
            $table->unsignedSmallInteger('printing_max_total_mb')->nullable();
            $table->unsignedSmallInteger('printing_max_files')->nullable();
            $table->unsignedSmallInteger('booking_max_image_mb')->nullable();
            $table->unsignedSmallInteger('booking_max_images')->nullable();
            $table->unsignedSmallInteger('booking_max_total_mb')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
