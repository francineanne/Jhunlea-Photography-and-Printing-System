<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The audit trail (Phase 8E): append-only, one new table, no change to any existing table. Rows are only ever
        // inserted (AuditLogger) and, past the retention limit, deleted by `audit:prune --force` run by the developer.
        // hash = SHA-256 of prev_hash + the row's fixed fields, so an edited, deleted or reordered row breaks the chain.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 120);
            $table->string('actor_role', 20);
            $table->string('action', 60)->index();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 120)->nullable();
            $table->string('summary', 255);
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->index(['subject_type', 'subject_id']);
        });

        // The chain head: ONE row (id 1) holding the newest row's id and hash. AuditLogger locks this row while it
        // writes, so two writers wait for each other instead of both reading the same prev_hash (an empty table has
        // no newest row to lock; a gap lock on audit_logs could deadlock). Created here so it always exists.
        Schema::create('audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->char('last_hash', 64);
        });

        DB::table('audit_chain_head')->insert(['id' => 1, 'last_id' => null, 'last_hash' => hash('sha256', (string) config('audit.genesis'))]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');
        Schema::dropIfExists('audit_logs');
    }
};
