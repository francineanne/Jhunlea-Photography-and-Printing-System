<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The messages of a conversation, plain text only. client_id makes a retried send idempotent (one row however
        // often the same send arrives). meta holds references only (an intent, recommended catalog ids, the order or
        // booking a message is about), never a price, name or link: cards are rebuilt from the catalog when shown
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('sender_type', 20);
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('client_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['conversation_id', 'client_id']);
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
