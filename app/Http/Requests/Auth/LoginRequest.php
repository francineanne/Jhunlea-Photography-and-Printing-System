<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Log the user in, or fail with one generic message so the form never reveals whether the email exists.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // An unknown email skips the password check and would answer faster than a known one; spend the same time
        if (! User::where('email', $this->string('email'))->exists()) {
            Hash::make($this->string('password'));
        }

        // A walk-in customer (Phase 8A) has no email or password and can never sign in; the condition says so outright
        if (! Auth::attempt($this->only('email', 'password') + ['is_walk_in' => false], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            $this->auditFailedOwnerLogin();

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Audit trail (8E): a failed sign-in to an OWNER account writes a row (no email, no password; the IP is the row's
     * own column), at most one per IP in config audit.failed_login_window_minutes, so a flood writes one row.
     */
    private function auditFailedOwnerLogin(): void
    {
        if (! User::query()->where('email', $this->string('email'))->where('role', UserRole::Owner)->exists()) {
            return;
        }

        $window = (int) config('audit.failed_login_window_minutes');

        if (Cache::add('audit.login_failed.'.sha1((string) $this->ip()), true, now()->addMinutes($window))) {
            app(AuditLogger::class)->record(AuditAction::LoginFailed);
        }
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', ['seconds' => $seconds]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
