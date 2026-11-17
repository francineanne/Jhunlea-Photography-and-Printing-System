<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10C: password reset with a one-time 6-digit code instead of a link.
 *
 * - request(): for an account that can sign in (an email, a password, not a walk-in) a new random code (random_int) is
 *   stored ONLY as a hash, valid TTL_MINUTES, replacing any earlier one (one active code per user), and emailed.
 *   Unknown and walk-in emails get nothing; the caller always shows the same answer.
 * - reset(): the email, the code and the new password together. A wrong code counts a try; after MAX_TRIES the code is
 *   burned (deleted). An expired code is deleted. One message for every refusal, so it never tells whether an email
 *   has an account. On success the password is saved (hashed by the model), the remember-me token replaced, EVERY
 *   session of the user deleted (other devices are signed out), the code deleted and a "your password was changed"
 *   email sent.
 * Rate limits (per IP on the routes, per email in the controller) are on top of the 5 tries.
 */
class PasswordResetCodeService
{
    public const TTL_MINUTES = 10;

    public const MAX_TRIES = 5;

    public const SENT_MESSAGE = 'If an account exists for that email, we sent a code.';

    public const INVALID_MESSAGE = 'That code is not valid or has expired. Check the latest email, or ask for a new code.';

    public function request(string $email): void
    {
        $user = $this->account($email);

        if ($user === null) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $code) {
            PasswordResetCode::query()->where('user_id', $user->id)->delete();
            (new PasswordResetCode)->forceFill([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => Carbon::now('UTC')->addMinutes(self::TTL_MINUTES),
            ])->save();
        });

        $user->notify(new PasswordResetCodeNotification($code, self::TTL_MINUTES));
    }

    /** @throws ValidationException with INVALID_MESSAGE on the code field when the code does not work */
    public function reset(string $email, string $code, string $password): User
    {
        $user = $this->account($email);
        $refuse = fn () => throw ValidationException::withMessages(['code' => self::INVALID_MESSAGE]);

        if ($user === null) {
            $refuse();
        }

        return DB::transaction(function () use ($user, $code, $password, $refuse) {
            $row = PasswordResetCode::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($row === null) {
                $refuse();
            }

            // An expired code is deleted; returned (not thrown) so the delete commits, refused below
            if ($row->expires_at->lte(Carbon::now('UTC'))) {
                $row->delete();

                return null;
            }

            if (! Hash::check($code, $row->code_hash)) {
                $row->attempts++;
                $row->attempts >= self::MAX_TRIES ? $row->delete() : $row->save();

                // Returned (not thrown) so the transaction commits and the wrong try is kept; refused below
                return null;
            }

            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            $row->delete();

            if ($user->role === UserRole::Owner) {
                app(AuditLogger::class)->record(AuditAction::PasswordChanged, null, ['count' => 0], actor: $user);
            }

            return $user;
        }) ?? $refuse();
    }

    /** Sent after a successful reset (and after the transaction), never with the password. */
    public function notifyChanged(User $user): void
    {
        $user->notify(new PasswordChangedNotification);
    }

    /** An account that can sign in with a password: never a walk-in, never an account without a password. */
    private function account(string $email): ?User
    {
        return User::query()->where('email', mb_strtolower(trim($email)))->where('is_walk_in', false)->whereNotNull('password')->first();
    }
}
