<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The studio's weekly opening hours, one row per weekday, in shop local time (config app.display_timezone)
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            // 0 = Sunday ... 6 = Saturday, as Carbon's dayOfWeek
            $table->unsignedTinyInteger('weekday')->unique();
            $table->boolean('is_open')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_rules');
    }
};
