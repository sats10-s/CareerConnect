<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @param  array{email: string, password: string}  $credentials
     */
    public function attemptLogin(array $credentials): User
    {
        $user = User::with('role')->where('email', $credentials['email'])->first();

        if (! $user || ! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Contact the placement office.'],
            ]);
        }

        return $user;
    }
}
