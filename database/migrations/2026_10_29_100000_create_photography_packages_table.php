<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photography_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unique across soft-deleted rows too, so a removed package keeps its slug for booking history
            $table->string('slug')->unique();
            $table->string('category');
            $table->string('short_description');
            $table->text('description')->nullable();
            // The listed or starting price; the owner confirms the final price when price_requires_confirmation is set
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('price_requires_confirmation')->default(false);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('max_people');
            $table->unsignedSmallInteger('digital_outputs')->nullable();
            $table->json('inclusions');
            $table->text('terms')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photography_packages');
    }
};
