<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Walk-in customers (Phase 8A): people the owner serves at the counter, with no login. They are ordinary
        // customer rows flagged is_walk_in, with no email and no password, so they can never sign in or reset a
        // password. The unique index on email stays: MySQL and SQLite allow any number of NULLs in a unique index.
        // Existing rows keep their email and password and become is_walk_in = false.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->boolean('is_walk_in')->default(false)->after('role')->index();
        });
    }

    public function down(): void
    {
        // Drops only the flag. Email and password stay nullable on purpose: walk-in rows have neither, so making the
        // columns required again would fail; remove those rows by hand first if that is ever needed
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_walk_in']);
            $table->dropColumn('is_walk_in');
        });
    }
};
