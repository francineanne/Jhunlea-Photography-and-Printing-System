<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photography_package_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt_text')->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['photography_package_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_images');
    }
};
