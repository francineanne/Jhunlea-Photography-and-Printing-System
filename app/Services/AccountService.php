<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ColorScheme;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person's own account (Phase 6C), the same for customers and the owner: profile, password and appearance. Only the
 * columns named here are ever written; the role, the id, email_verified_at and the timestamps never come from a request.
 */
class AccountService
{
    /**
     * Saves name, email and phone. Saving the values already stored changes nothing (a double submit is harmless).
     *
     * @param  array{name: string, email: string, phone: ?string}  $profile
     * @return array{changed: bool, emailChanged: bool}
     */
    public function updateProfile(User $user, array $profile): array
    {
        $user->forceFill([
            'name' => $profile['name'],
            'email' => $profile['email'],
            'phone' => $profile['phone'],
        ]);

        $emailChanged = $user->isDirty('email');
        $changed = $user->isDirty();

        if ($changed) {
            $user->save();
        }

        // Audit trail (8E), the owner's account only: that the email changed, never the address itself
        if ($emailChanged && $user->role === UserRole::Owner) {
            app(AuditLogger::class)->record(AuditAction::EmailChanged, null, actor: $user);
        }

        return ['changed' => $changed, 'emailChanged' => $emailChanged];
    }

    /**
     * Saves the new password (hashed by the model cast), gives this session a new id and signs every other device out:
     * the other rows of this person in the sessions table are deleted and the remember-me token is replaced, so a
     * remembered login elsewhere stops working too. The current session stays signed in.
     */
    public function changePassword(User $user, string $password, Session $session): int
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        $session->migrate(true);

        $signedOut = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $session->getId())
            ->delete();

        // Audit trail (8E), the owner's account only: never the password, the token or a session id
        if ($user->role === UserRole::Owner) {
            app(AuditLogger::class)->record(AuditAction::PasswordChanged, null, actor: $user);
            if ($signedOut > 0) {
                app(AuditLogger::class)->record(AuditAction::SessionsSignedOut, null, ['count' => $signedOut], actor: $user);
            }
        }

        return $signedOut;
    }

    public function setTheme(User $user, ColorScheme $theme): void
    {
        if ($user->theme !== $theme) {
            $user->forceFill(['theme' => $theme])->save();
        }
    }
}
