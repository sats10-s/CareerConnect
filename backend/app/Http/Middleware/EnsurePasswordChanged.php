<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A student signed in with their first-time password (mobile number or
 * one-time password) can do nothing but set their own password and personal
 * email: every route behind this middleware answers 403 until they have.
 * /auth/me, /auth/logout and /auth/first-login sit outside it.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'Set a new password before continuing.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
