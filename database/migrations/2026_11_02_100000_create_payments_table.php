<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 20)->unique();
            // A printing order or a photography booking, by its morph map alias (never a class name)
            $table->string('payable_type', 40);
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 10, 2);
            $table->string('method', 16);
            $table->string('gcash_reference', 40)->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->string('status', 16)->default('recorded');
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            // 1 while the payment is recorded, NULL once voided. With the unique index below the database itself
            // refuses a second recorded payment for the same order or booking; voided rows (NULL) never collide
            $table->unsignedTinyInteger('active')->nullable();
            $table->timestamps();

            $table->unique(['payable_type', 'payable_id', 'active']);
            // The same GCash reference can belong to one recorded payment only
            $table->unique(['gcash_reference', 'active']);
            $table->index(['payable_type', 'payable_id']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
