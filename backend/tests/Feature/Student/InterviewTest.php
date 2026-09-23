<?php

namespace Tests\Feature\Student;

use App\Enums\ApplicationStatus;
use App\Enums\RoleName;
use App\Models\Application;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/student/interviews[/{interview}] - a student's own
 * interviews, tied to their own applications. See
 * Api\Student\InterviewController and App\Policies\InterviewPolicy
 * (the IDOR/BOLA guard - ownership is always derived by walking
 * interview -> application -> student, never trusted from the URL).
 */
class InterviewTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Batch $batch;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
        $this->company = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);
        $this->admin = $this->makeUser(RoleName::PLACEMENT_ADMIN);
    }

    private function makeUser(RoleName $role, array $overrides = []): User
    {
        $roleModel = Role::where('name', $role->value)->firstOrFail();

        return User::create(array_merge([
            'name' => 'Test User',
            'email' => strtolower($role->value).'-'.uniqid().'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $roleModel->id,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides student column overrides.
     */
    private function makeStudent(array $overrides = []): User
    {
        $user = $this->makeUser(RoleName::STUDENT, array_intersect_key($overrides, array_flip(['email'])));

        Student::create(array_merge([
            'user_id' => $user->id,
            'roll_number' => 'CC24'.random_int(1000, 9999),
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 3,
            'cgpa' => 8.0,
            'backlogs' => 0,
        ], array_diff_key($overrides, array_flip(['email']))));

        return $user;
    }

    private function makeJobPosting(array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Software Engineer I',
            'status' => 'published',
        ], $overrides));
    }

    /**
     * Applies as $student, fast-forwards the application to
     * SHORTLISTED, then creates an interview on it through the real
     * admin endpoint - so every test's fixture interview is one the
     * app itself could actually have produced.
     *
     * @param array<string, mixed> $overrides interview payload overrides.
     */
    private function makeInterview(User $student, JobPosting $jobPosting, array $overrides = []): array
    {
        Sanctum::actingAs($student, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data.id');

        Application::findOrFail($applicationId)->update(['status' => ApplicationStatus::SHORTLISTED]);

        Sanctum::actingAs($this->admin, ['*']);
        $payload = array_merge([
            'round' => 1,
            'title' => 'Technical Interview',
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
            'duration_minutes' => 60,
            'mode' => 'ONLINE',
            'meeting_url' => 'https://meet.example.com/room123',
            'interviewer_name' => 'Jane Doe',
            'interviewer_email' => 'jane.doe@novatech.example',
            'panel_notes' => 'Internal only - focus on system design.',
        ], $overrides);

        return $this->postJson("/api/admin/applications/{$applicationId}/interviews", $payload)->json('data');
    }

    public function test_student_can_list_own_interviews(): void
    {
        $jobPosting = $this->makeJobPosting();
        $student = $this->makeStudent();
        $this->makeInterview($student, $jobPosting);

        Sanctum::actingAs($student, ['*']);
        $response = $this->getJson('/api/student/interviews');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['round' => 1]);
    }

    public function test_student_can_view_own_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $student = $this->makeStudent();
        $interview = $this->makeInterview($student, $jobPosting);

        Sanctum::actingAs($student, ['*']);
        $response = $this->getJson("/api/student/interviews/{$interview['id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $interview['id']);
        $response->assertJsonPath('data.mode', 'ONLINE');
        $response->assertJsonPath('data.meeting_url', 'https://meet.example.com/room123');
    }

    public function test_student_cannot_view_another_students_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $owner = $this->makeStudent(['email' => 'owner@careerconnect.edu']);
        $interview = $this->makeInterview($owner, $jobPosting);

        $intruder = $this->makeStudent(['email' => 'intruder@careerconnect.edu']);
        Sanctum::actingAs($intruder, ['*']);

        $this->getJson("/api/student/interviews/{$interview['id']}")->assertStatus(403);
    }

    public function test_guest_cannot_view_interviews(): void
    {
        $jobPosting = $this->makeJobPosting();
        $student = $this->makeStudent();
        $interview = $this->makeInterview($student, $jobPosting);

        // makeInterview() ends by acting as $this->admin (it drives the
        // real admin endpoint to create the fixture interview), and
        // Sanctum::actingAs() sets the resolved user directly on the
        // guard - that stays in effect for any later request in this
        // test until something re-authenticates or the guard is reset.
        // Every other test here calls Sanctum::actingAs() again after
        // makeInterview() and so never notices this; this is the one
        // test that actually wants a logged-out request, so it must
        // explicitly forget the guards first - otherwise this "guest"
        // request is still authenticated as the admin, and gets 403
        // (wrong role) from EnsureUserHasRole instead of the intended
        // 401 (no user at all).
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/student/interviews')->assertStatus(401);
        $this->getJson("/api/student/interviews/{$interview['id']}")->assertStatus(401);
    }

    public function test_internal_admin_notes_are_not_exposed_to_student(): void
    {
        $jobPosting = $this->makeJobPosting();
        $student = $this->makeStudent();
        $interview = $this->makeInterview($student, $jobPosting);

        Sanctum::actingAs($student, ['*']);
        $response = $this->getJson("/api/student/interviews/{$interview['id']}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.panel_notes');
        $response->assertJsonMissingPath('data.interviewer_email');
        // Fields the student SHOULD see remain present.
        $response->assertJsonPath('data.meeting_url', 'https://meet.example.com/room123');
        $response->assertJsonPath('data.interviewer_name', 'Jane Doe');
    }
}
