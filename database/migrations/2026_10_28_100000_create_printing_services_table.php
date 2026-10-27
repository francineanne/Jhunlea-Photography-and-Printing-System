<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printing_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unique across soft-deleted rows too, so a removed service keeps its slug for order history
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('pricing_type');
            $table->decimal('base_price', 10, 2)->nullable();
            $table->json('allowed_file_types');
            $table->unsignedSmallInteger('max_file_size_mb');
            $table->text('instructions')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printing_services');
    }
};
