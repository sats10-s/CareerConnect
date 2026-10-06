<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordService;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly AuthService $authService,
        private readonly AuditLogger $audit,
        private readonly PasswordService $passwords,
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
        $user = $this->authService->attemptLogin($request->identifier(), $request->validated('password'), $request->ip(), $request->identifierField());
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->record('auth.login', "{$user->name} signed in", $user, [], $user);

        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $token = $user->createToken('careerconnect-api')->plainTextToken;

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }

    /**
     * The forced first-login step for an account still on its first-time
     * password: set a new one (and, for students, a personal email).
     */
    public function firstLogin(FirstLoginRequest $request): UserResource
    {
        $user = $this->passwords->completeFirstLogin(
            $request->user(),
            $request->validated('password'),
            $request->validated('personal_email'),
        );

        return new UserResource($user);
    }

    /**
     * Always the same answer, whether or not the account exists or has a
     * reset email - never a way to probe for accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255']]);
        $key = 'forgot-password:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many requests. Please wait a few minutes and try again.'], 429);
        }
        RateLimiter::hit($key, 600);

        $login = PasswordService::normalizeLogin($data['login']);
        $this->passwords->sendResetLink($this->authService->findByIdentifier($login), $login);

        return response()->json([
            'message' => 'If that account has a personal email on file, a reset link is on its way. Check your inbox (and spam folder).',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = $this->authService->findByIdentifier(PasswordService::normalizeLogin($request->validated('login')));
        $this->passwords->resetWithToken($user, $request->validated('token'), $request->validated('password'));

        return response()->json(['message' => 'Password changed. You can sign in with it now.']);
    }

    public function logout(Request $request): JsonResponse
    {
        // currentAccessToken() is a real, deletable PersonalAccessToken
        // (an Eloquent model) only for a bearer-token client. For a
        // stateful SPA/session request, Sanctum's guard instead attaches
        // a Laravel\Sanctum\TransientToken stand-in - which has no
        // delete() method at all - so calling ->delete() unconditionally
        // throws for every session-authenticated logout. The session
        // itself (invalidated below, when present) is what actually
        // needs revoking in that case; there is no token row to delete.
        $accessToken = $request->user()?->currentAccessToken();

        if ($request->user()) {
            $this->audit->record('auth.logout', "{$request->user()->name} signed out", $request->user());
        }

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

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
        $user = $request->user()->load(['role', 'student.department', 'student.batch', 'faculty.department', 'company']);

        return new UserResource($user);
    }
}
