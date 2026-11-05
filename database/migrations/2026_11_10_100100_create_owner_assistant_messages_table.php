<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owner's own Ask Lumi conversation (Phase 7E-3a): the owner's questions and Lumi's answers, so the next
        // question can read the last few. Never tool data, never a customer's message. Written only by OwnerAskHistory
        Schema::create('owner_assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10);
            $table->text('text');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['owner_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_assistant_messages');
    }
};
