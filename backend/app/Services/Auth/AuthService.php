<?php

namespace App\Services\Auth;

use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\JntuNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for every role. Students sign in with their JNTU No, staff with
 * their email.
 *
 *  - Per account: 5 failed attempts lock the account for 15 minutes.
 *  - Per IP: at most 30 attempts per 10 minutes, whatever the account.
 *  - A first password issued by the Student Data import (the mobile number,
 *    or a one-time password) stops working if it isn't changed within 30
 *    days; the Super Admin then resets it.
 *
 * Every failure is written to the audit log.
 */
class AuthService
{
    public const MAX_FAILED_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public const IP_MAX_ATTEMPTS = 30;

    public const IP_DECAY_SECONDS = 600;

    public const FIRST_PASSWORD_DAYS = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    public function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            // Case-insensitive on every database (MySQL's collation already is; SQLite isn't).
            return User::with('role')->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])->first();
        }

        $student = Student::query()->where('roll_number', JntuNumber::normalize($identifier))->first();

        return $student ? User::with('role')->find($student->user_id) : null;
    }

    /**
     * @param  string  $field  the request key errors are reported under
     */
    public function attemptLogin(string $identifier, string $password, ?string $ip, string $field = 'login'): User
    {
        $ipKey = 'login-ip:'.($ip ?? 'unknown');

        if (RateLimiter::tooManyAttempts($ipKey, self::IP_MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($ipKey) / 60);
            $this->audit->record('auth.login_locked', 'Sign-in throttled for this network address', null, ['reason' => 'ip']);

            throw ValidationException::withMessages([$field => ["Too many sign-in attempts. Try again in {$minutes} minute(s)."]])->status(429);
        }
        RateLimiter::hit($ipKey, self::IP_DECAY_SECONDS);

        $user = $this->findByIdentifier($identifier);
        $label = $this->label($identifier);

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));
            $this->audit->record('auth.login_locked', "Sign-in refused for locked account {$label}", $user, [], $user);

            throw ValidationException::withMessages([$field => ["Too many failed attempts. This account is locked for {$minutes} more minute(s)."]])->status(429);
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->recordFailure($user, $label);

            throw ValidationException::withMessages([$field => ['The provided credentials are incorrect.']]);
        }

        if (! $user->is_active) {
            $this->audit->record('auth.login_blocked', "Sign-in blocked for deactivated account {$label}", $user, [], $user);

            throw ValidationException::withMessages([$field => ['This account has been deactivated. Contact the placement office.']]);
        }

        if ($this->firstPasswordExpired($user)) {
            $this->audit->record('auth.login_blocked', "Sign-in blocked: first-time password expired for {$label}", $user, ['reason' => 'first_password_expired'], $user);

            throw ValidationException::withMessages([$field => ['Your first-time password has expired. Ask the placement office to reset it.']]);
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->saveQuietly();

        return $user;
    }

    public function firstPasswordExpired(User $user): bool
    {
        return $user->must_change_password
            && $user->password_changed_at === null
            && $user->credentials_issued_at !== null
            && $user->credentials_issued_at->lt(now()->subDays(self::FIRST_PASSWORD_DAYS));
    }

    private function recordFailure(?User $user, string $label): void
    {
        // Keyed 'email' for the Security overview's per-account grouping; for a student it holds the JNTU No.
        $this->audit->record('auth.login_failed', "Failed sign-in for {$label}", $user, ['email' => $label], $user);

        if ($user === null) {
            return;
        }

        $failed = $user->failed_logins + 1;

        if ($failed >= self::MAX_FAILED_ATTEMPTS) {
            $user->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->saveQuietly();
            $this->audit->record('auth.login_locked', "Locked {$label} for ".self::LOCK_MINUTES.' minutes after '.self::MAX_FAILED_ATTEMPTS.' failed attempts', $user, [], $user);

            return;
        }

        $user->forceFill(['failed_logins' => $failed])->saveQuietly();
    }

    /** What the audit log calls the account: the email, or the normalized JNTU No. */
    private function label(string $identifier): string
    {
        $identifier = mb_substr(trim($identifier), 0, 120);

        return str_contains($identifier, '@') ? $identifier : JntuNumber::normalize($identifier);
    }
}
