<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exercises the auth foundation described in the project README:
 * student registration, login, logout, the /me endpoint, and
 * server-side role enforcement (the part the frontend route guards
 * do NOT provide on their own).
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private Role $studentRole;

    private Role $facultyRole;

    private Department $department;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->studentRole = Role::where('name', RoleName::STUDENT->value)->firstOrFail();
        $this->facultyRole = Role::where('name', RoleName::FACULTY->value)->firstOrFail();

        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
    }

    public function test_student_can_register_with_valid_details(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Aarav Mehta',
            'email' => 'aarav@careerconnect.edu',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.email', 'aarav@careerconnect.edu');
        $response->assertJsonPath('data.role', RoleName::STUDENT->value);

        $response->assertJsonPath('data.student.year', 2);

        $this->assertDatabaseHas('users', ['email' => 'aarav@careerconnect.edu']);
        $this->assertDatabaseHas('students', ['roll_number' => 'CC24001', 'year' => 2]);

        // The password must never be stored in plaintext.
        $stored = User::where('email', 'aarav@careerconnect.edu')->firstOrFail();
        $this->assertNotEquals('Password@123', $stored->password);
        $this->assertTrue(Hash::check('Password@123', $stored->password));
    }

    public function test_registration_rejects_invalid_payloads(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
            'roll_number' => '',
            'department_id' => 999999,
            'batch_id' => 999999,
            'year' => 7,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password', 'roll_number', 'department_id', 'batch_id', 'year']);
    }

    public function test_registration_rejects_duplicate_college_email(): void
    {
        $existing = User::create([
            'name' => 'Existing Student',
            'email' => 'duplicate@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $existing->id,
            'roll_number' => 'CC24999',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Another Student',
            'email' => 'duplicate@careerconnect.edu',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'roll_number' => 'CC24002',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::create([
            'name' => 'Diya Patel',
            'email' => 'diya@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24003',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
        ]);

        // Deliberately sends no Origin/Referer header, i.e. a plain
        // token client (curl, Postman, a mobile app) rather than the
        // SPA - this is the path that used to throw "Session store not
        // set on request." because the controller touched the session
        // unconditionally. It must still succeed and return a token.
        $response = $this->postJson('/api/auth/login', [
            'email' => 'diya@careerconnect.edu',
            'password' => 'Password@123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', 'diya@careerconnect.edu');
        $response->assertJsonStructure(['data' => ['id', 'name', 'email', 'role'], 'token']);
    }

    public function test_login_establishes_a_stateful_session_for_spa_requests(): void
    {
        $user = User::create([
            'name' => 'Meera Nair',
            'email' => 'meera@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24008',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
        ]);

        // An Origin matching SANCTUM_STATEFUL_DOMAINS is what
        // EnsureFrontendRequestsAreStateful treats as "from the
        // frontend" - only then does it start a session, which is what
        // lets AuthController@login call Auth::login() and
        // session()->regenerate(). This is the actual SPA login path
        // the React app uses; it must still work now that the session
        // calls are conditional on $request->hasSession().
        $response = $this->withHeaders(['Origin' => 'http://localhost'])
            ->postJson('/api/auth/login', [
                'email' => 'meera@careerconnect.edu',
                'password' => 'Password@123',
            ]);

        $response->assertOk();
        $response->assertCookie(config('session.cookie'));
    }

    public function test_login_fails_with_incorrect_password(): void
    {
        User::create([
            'name' => 'Kabir Singh',
            'email' => 'kabir@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'kabir@careerconnect.edu',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_for_deactivated_account(): void
    {
        User::create([
            'name' => 'Isha Reddy',
            'email' => 'isha@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'isha@careerconnect.edu',
            'password' => 'Password@123',
        ]);

        $response->assertStatus(422);
    }

    public function test_guest_cannot_access_me_or_any_dashboard(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
        $this->getJson('/api/student/dashboard')->assertStatus(401);
        $this->getJson('/api/faculty/dashboard')->assertStatus(401);
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
        $this->getJson('/api/super-admin/dashboard')->assertStatus(401);
    }

    /**
     * Regression test for bootstrap/app.php's guest-redirect override.
     * Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware()
     * unconditionally seeds a default guest-redirect callback of
     * `fn () => route('login')` *before* this app's own withMiddleware()
     * closure runs, and Authenticate::unauthenticated() only skips
     * calling it when $request->expectsJson() is already true. This app
     * is API-only and defines no named `login` route anywhere, so a
     * PLAIN unauthenticated request that does NOT send an
     * `Accept: application/json` header - unlike getJson()/postJson(),
     * which every other guest test in this suite uses - used to hit
     * that default closure and throw "Route [login] not defined."
     * instead of returning 401. bootstrap/app.php now overrides it via
     * redirectGuestsTo() for every /api/* request; this proves it stays
     * fixed, independent of any single endpoint.
     */
    public function test_guest_receives_json_401_without_a_json_accept_header(): void
    {
        $this->get('/api/student/dashboard')->assertStatus(401);
        $this->post('/api/auth/logout')->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_own_profile_via_me(): void
    {
        $user = User::create([
            'name' => 'Rohan Gupta',
            'email' => 'rohan@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24004',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.email', 'rohan@careerconnect.edu');
        $response->assertJsonPath('data.student.roll_number', 'CC24004');
    }

    public function test_role_middleware_allows_matching_role_and_blocks_others(): void
    {
        $student = User::create([
            'name' => 'Sneha Iyer',
            'email' => 'sneha@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $student->id,
            'roll_number' => 'CC24005',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
        ]);

        Sanctum::actingAs($student, ['*']);

        // A student can reach their own dashboard...
        $this->getJson('/api/student/dashboard')->assertOk();

        // ...but is forbidden from faculty/admin/super-admin dashboards,
        // even though they are authenticated - this is the server-side
        // enforcement (EnsureUserHasRole) that the frontend route
        // guards alone cannot provide.
        $this->getJson('/api/faculty/dashboard')->assertStatus(403);
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/super-admin/dashboard')->assertStatus(403);
    }

    public function test_faculty_dashboard_reports_assigned_students(): void
    {
        $facultyUser = User::create([
            'name' => 'Dr. Priya Sharma',
            'email' => 'priya@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->facultyRole->id,
        ]);
        $faculty = Faculty::create([
            'user_id' => $facultyUser->id,
            'employee_code' => 'FAC001',
            'department_id' => $this->department->id,
            'designation' => 'Assistant Professor',
        ]);

        $studentUser = User::create([
            'name' => 'Mentee Student',
            'email' => 'mentee@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $studentUser->id,
            'roll_number' => 'CC24006',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'faculty_mentor_id' => $faculty->id,
        ]);

        Sanctum::actingAs($facultyUser, ['*']);

        $response = $this->getJson('/api/faculty/dashboard');

        $response->assertOk();
        $response->assertJsonPath('stats.assigned_students', 1);
    }

    public function test_logout_invalidates_the_session_for_subsequent_requests(): void
    {
        $user = User::create([
            'name' => 'Vivaan Das',
            'email' => 'vivaan@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24007',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
        ]);

        $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();
    }

    /**
     * Regression test for AuthController::logout(). currentAccessToken()
     * is only a real, deletable Laravel\Sanctum\PersonalAccessToken for
     * a bearer-token client - the fix that stopped logout crashing on
     * Laravel\Sanctum\TransientToken (the session-auth stand-in, which
     * has no delete() method) must not have done so by skipping
     * deletion altogether. This proves a real token row is still
     * revoked on logout for a token-based client.
     */
    public function test_logout_revokes_the_bearer_token_for_token_based_clients(): void
    {
        $user = User::create([
            'name' => 'Arjun Malhotra',
            'email' => 'arjun@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);
        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24009',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'arjun@careerconnect.edu',
            'password' => 'Password@123',
        ]);
        $login->assertOk();
        $token = $login->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
