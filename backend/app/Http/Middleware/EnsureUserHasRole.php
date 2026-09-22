<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side role gate. Frontend route guards only control what the
 * UI *shows*; this middleware is what actually enforces access -
 * every protected API route must be behind it (or an equivalent
 * policy check), never rely on the frontend alone.
 *
 * Usage: ->middleware('role:STUDENT') or ->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userRole = $user->roleName();

        $allowed = collect($roles)
            ->map(fn (string $role) => RoleName::tryFrom($role))
            ->filter();

        if (! $userRole || ! $allowed->contains($userRole)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return $next($request);
    }
}
