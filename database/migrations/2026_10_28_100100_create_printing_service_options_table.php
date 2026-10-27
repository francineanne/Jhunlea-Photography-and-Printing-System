<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printing_service_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printing_service_id')->constrained('printing_services')->cascadeOnDelete();
            $table->string('group');
            $table->string('label');
            $table->decimal('price_modifier', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['printing_service_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printing_service_options');
    }
};
