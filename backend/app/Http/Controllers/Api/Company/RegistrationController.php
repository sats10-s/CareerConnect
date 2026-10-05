<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\RegisterCompanyRequest;
use App\Http\Resources\UserResource;
use App\Services\CompanyRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * POST /api/company/register - public, rate limited (see the
 * `company-registration` limiter in AppServiceProvider). Creates a PENDING
 * company plus its OWNER login and signs the SPA in, exactly like student
 * self-registration does, so the recruiter lands on their status page.
 *
 * No email verification step: this install has no outbound mail configured
 * (MAIL_MAILER=log), so the Super Admin review is the only gate.
 */
class RegistrationController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly CompanyRegistrationService $registration) {}

    public function store(RegisterCompanyRequest $request): JsonResponse
    {
        $user = $this->registration->register($request->validated());

        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return $this->success(
            data: new UserResource($user),
            message: 'Registration received. A Super Admin will review your company shortly.',
            status: 201,
        );
    }
}
