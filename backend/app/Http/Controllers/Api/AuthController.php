<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly AuthService $authService,
    ) {}

    /**
     * Public student self-registration.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->registrationService->registerStudent($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Session-based login (SPA, via Sanctum). Also issues a bearer
     * token so the same endpoint works for non-cookie clients.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->attemptLogin($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        $token = $user->createToken('careerconnect-api')->plainTextToken;

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * The currently authenticated user, with role + profile loaded -
     * this is what the SPA calls on boot to decide which dashboard to
     * render and to protect client-side routes.
     */
    public function me(Request $request): UserResource
    {
        $user = $request->user()->load(['role', 'student.department', 'student.batch', 'faculty.department']);

        return new UserResource($user);
    }
}
