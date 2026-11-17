<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10C (accounts). Safe on a database that holds rows:
 * 1. Every account that already exists and can sign in (the owner and every customer with an email; never a walk-in)
 *    is marked verified now: they existed before email verification became a rule. Walk-ins stay as they are.
 * 2. password_reset_codes: one active 6-digit reset code per user, stored only as a hash (PasswordResetCodeService).
 * 3. users.avatar_path: the profile picture on the public disk (avatars/<random name>), NULL = initials.
 *
 * down() drops the table and the column; the verified marks stay (they are harmless and cannot be told apart from
 * real verifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->verifyExistingAccounts();

        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path', 120)->nullable()->after('theme');
        });
    }

    /** Everyone who can already sign in (an email and not a walk-in) and is not verified yet counts as verified now. */
    private function verifyExistingAccounts(): void
    {
        DB::table('users')->whereNull('email_verified_at')->whereNotNull('email')->where('is_walk_in', false)
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
        Schema::dropIfExists('password_reset_codes');
    }
};
