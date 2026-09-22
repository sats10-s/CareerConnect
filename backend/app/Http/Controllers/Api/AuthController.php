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

        // Only touch the session when this request actually went through
        // Sanctum's stateful pipeline (EnsureFrontendRequestsAreStateful,
        // enabled via ->statefulApi() in bootstrap/app.php). That
        // middleware only starts a session for requests whose
        // Origin/Referer matches SANCTUM_STATEFUL_DOMAINS - i.e. the SPA.
        // A token-only client (curl, Postman, a mobile app) never gets a
        // session store attached to the request, so calling
        // $request->session() unconditionally throws "Session store not
        // set on request." $request->hasSession() is the documented way
        // to branch on that without guessing from headers ourselves.
        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Works as both a session-based login for the SPA (Sanctum's stateful
     * cookie auth, when the request comes from a stateful domain) and a
     * plain token endpoint for any other client - the same response
     * always includes a bearer token, and the session is only touched
     * when one is actually present on the request.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->attemptLogin($request->validated());

        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $token = $user->createToken('careerconnect-api')->plainTextToken;

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

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
