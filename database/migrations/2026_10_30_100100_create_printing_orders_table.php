<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printing_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 20)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('printing_service_id')->constrained('printing_services')->restrictOnDelete();
            // The service, the chosen options and their prices as they were when the order was placed
            $table->json('snapshot');
            $table->unsignedInteger('quantity');
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('submitted');
            $table->decimal('estimated_price', 10, 2)->nullable();
            $table->decimal('confirmed_price', 10, 2)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('info_request')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printing_orders');
    }
};
