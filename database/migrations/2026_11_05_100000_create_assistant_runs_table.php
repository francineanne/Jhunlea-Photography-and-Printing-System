<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per assistant call (Phase 7), metadata only: the limits and the daily budget are counted from here.
        // There is no column for any message, prompt or reply text, and guests are kept only as a hash of their IP
        Schema::create('assistant_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_key', 64)->nullable();
            $table->string('feature', 40);
            $table->string('provider', 20);
            $table->string('model', 80)->nullable();
            $table->string('outcome', 40);
            $table->string('intent', 20)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            // Set only when the provider was really called; the limits count these rows
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['created_at', 'feature']);
            $table->index(['user_id', 'created_at']);
            $table->index(['guest_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_runs');
    }
};
