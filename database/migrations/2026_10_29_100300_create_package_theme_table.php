<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_theme', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photography_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained()->cascadeOnDelete();

            $table->unique(['photography_package_id', 'theme_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_theme');
    }
};
