<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer's conversations (Phase 7B): many Ask Lumi ones ("assistant") and exactly ONE with the studio. The
        // studio row has thread_key "studio" (NULL on the others), so the unique index allows only one per customer,
        // even when two requests create it at the same moment. The last_* columns keep the inbox to one query
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('thread_key', 20)->nullable();
            $table->boolean('needs_owner')->default(false);
            $table->timestamp('needs_owner_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_sender_type', 20)->nullable();
            $table->string('last_message_preview', 120)->nullable();
            $table->unsignedBigInteger('customer_last_read_message_id')->nullable();
            $table->unsignedBigInteger('owner_last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'thread_key']);
            $table->index(['kind', 'last_message_at']);
            $table->index(['customer_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
