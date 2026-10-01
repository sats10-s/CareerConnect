<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     */
    public function attemptLogin(array $credentials): User
    {
        $user = User::with('role')->where('email', $credentials['email'])->first();

        if (! $user || ! Auth::validate($credentials)) {
            $this->audit->record('auth.login_failed', 'Failed sign-in for '.$credentials['email'], $user, ['email' => $credentials['email']], $user);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            $this->audit->record('auth.login_blocked', "Sign-in blocked for deactivated account {$user->email}", $user, [], $user);

            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Contact the placement office.'],
            ]);
        }

        return $user;
    }
}
