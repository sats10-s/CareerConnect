<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every company-portal route except "who am I" and the profile
 * edit (GET /company/me, PATCH /company/profile), which stay open while a
 * registration is pending so the recruiter can see their status and fix
 * their details. Anything else needs the user's own company - always
 * derived from the authenticated user, never from the request - to be
 * APPROVED.
 *
 * Usage: ->middleware(['role:COMPANY', 'company.approved'])
 */
class EnsureCompanyApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $company = $user->roleName() === RoleName::COMPANY ? $user->company : null;

        if (! $company || $company->status !== CompanyStatus::APPROVED) {
            return response()->json([
                'success' => false,
                'message' => match ($company?->status) {
                    CompanyStatus::PENDING => 'Your company registration is still being reviewed.',
                    CompanyStatus::REJECTED => 'Your company registration was not approved.',
                    CompanyStatus::SUSPENDED => 'Your company account is suspended.',
                    default => 'This action is unauthorized.',
                },
                'errors' => ['company_status' => [$company?->status?->value ?? 'NONE']],
            ], 403);
        }

        return $next($request);
    }
}
