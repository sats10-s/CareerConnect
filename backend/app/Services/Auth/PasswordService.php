<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\PasswordResetLink;
use App\Services\AuditLogger;
use App\Support\JntuNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that sets a password outside the sign-in form itself:
 * the forced first-login change, the Super Admin's reset back to
 * "mobile number + forced change", and forgot-password links (Laravel's
 * password broker tokens, mailed to the student's personal email).
 */
class PasswordService
{
    /**
     * bcrypt cost for first-time passwords (mobile number / one-time
     * password). They are temporary - forced change at first sign-in, dead
     * after 30 days - and an import hashes thousands at once: cost 12 took
     * ~10 minutes for the college's 2,500 students, cost 10 about a quarter
     * of that. Passwords students choose themselves keep the app default.
     */
    public const FIRST_PASSWORD_ROUNDS = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public static function hashFirstPassword(string $plain): string
    {
        return Hash::make($plain, ['rounds' => min(self::FIRST_PASSWORD_ROUNDS, (int) config('hashing.bcrypt.rounds', 12))]);
    }

    /**
     * A new password may not be the mobile number or the JNTU No - those are
     * the two things anyone who knows the student also knows.
     */
    public function assertNotGuessable(User $user, string $password, string $field = 'password'): void
    {
        $student = $user->student;
        $normalized = mb_strtoupper(trim($password));

        if ($student && ($normalized === mb_strtoupper((string) $student->roll_number) || ($student->phone && trim($password) === $student->phone))) {
            throw ValidationException::withMessages([$field => ['Choose a password that is not your mobile number or JNTU No.']]);
        }
    }

    public function completeFirstLogin(User $user, string $password, ?string $personalEmail): User
    {
        $this->assertNotGuessable($user, $password);

        DB::transaction(function () use ($user, $password, $personalEmail) {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            if ($personalEmail !== null && $user->student) {
                $user->student->update(['personal_email' => $personalEmail]);
            }
        });

        $this->audit->record('auth.password_changed', "{$user->name} set their own password", $user, ['first_login' => true], $user);

        return $user->fresh(['role', 'student.department', 'student.batch']);
    }

    /**
     * Super Admin reset: back to the mobile number as the first password
     * (or a one-time password when there is no valid mobile), with a forced
     * change and a fresh 30-day window. Signs the student out everywhere.
     *
     * @return ?string the one-time password, when one had to be generated
     */
    public function resetStudentToFirstLogin(User $user, User $actor): ?string
    {
        $phone = $user->student?->phone;
        $oneTimePassword = $phone && preg_match('/^\d{10}$/', $phone) ? null : Str::password(10, symbols: false);

        $user->forceFill([
            'password' => self::hashFirstPassword($oneTimePassword ?? $phone),
            'must_change_password' => true,
            'credentials_issued_at' => now(),
            'password_changed_at' => null,
            'failed_logins' => 0,
            'locked_until' => null,
        ])->save();

        $user->tokens()->delete();
        if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->audit->record(
            'student.password_reset',
            "Reset {$user->student?->roll_number} to first-login".($oneTimePassword ? ' with a one-time password' : ' (mobile number)'),
            $user,
            ['one_time_password' => $oneTimePassword !== null],
            $actor,
        );

        return $oneTimePassword;
    }

    /** Where a reset link for this user goes: a student's personal email, otherwise the account email. */
    public function resetAddressFor(User $user): ?string
    {
        return $user->student?->personal_email ?: $user->email;
    }

    /**
     * Sends a reset link when the account has somewhere to send it. Callers
     * always show the same response, so this never reveals whether an
     * account exists.
     */
    public function sendResetLink(?User $user, string $login): void
    {
        $address = $user ? $this->resetAddressFor($user) : null;

        if (! $user || ! $address || ! $user->is_active) {
            $this->audit->record('auth.password_reset_requested', 'Password reset requested for an account with no reset email', $user, ['sent' => false], $user);

            return;
        }

        $token = Password::broker()->createToken($user);
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'login' => $login]);

        Notification::route('mail', $address)->notify(new PasswordResetLink($url, $user->name));

        $this->audit->record('auth.password_reset_requested', "Password reset link sent for {$user->name}", $user, ['sent' => true], $user);
    }

    public function resetWithToken(?User $user, string $token, string $password): void
    {
        if (! $user || ! Password::broker()->tokenExists($user, $token)) {
            throw ValidationException::withMessages(['token' => ['This reset link is invalid or has expired. Request a new one.']]);
        }

        $this->assertNotGuessable($user, $password);

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'failed_logins' => 0,
            'locked_until' => null,
        ])->save();

        Password::broker()->deleteToken($user);
        $user->tokens()->delete();

        $this->audit->record('auth.password_reset_completed', "{$user->name} reset their password by email link", $user, [], $user);
    }

    public static function normalizeLogin(string $login): string
    {
        $login = trim($login);

        return str_contains($login, '@') ? $login : JntuNumber::normalize($login);
    }
}
