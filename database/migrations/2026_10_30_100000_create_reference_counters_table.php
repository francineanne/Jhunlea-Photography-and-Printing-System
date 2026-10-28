<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per reference prefix (PR now, PB for bookings later). The row is locked while a number is taken
        Schema::create('reference_counters', function (Blueprint $table) {
            $table->string('prefix', 8)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_counters');
    }
};
