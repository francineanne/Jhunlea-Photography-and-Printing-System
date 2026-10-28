<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printing_order_id')->constrained('printing_orders')->cascadeOnDelete();
            // Null on the first row, when the order is placed
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['printing_order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
