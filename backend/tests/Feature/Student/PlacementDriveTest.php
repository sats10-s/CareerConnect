<?php

namespace Tests\Feature\Student;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\JobPosting;
use App\Models\PlacementDrive;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/student/placement-drives[/{placementDrive}] - the
 * student-facing view of PUBLISHED placement drives only. PRODUCT
 * RULE - do not weaken this: there is no second eligibility-filtering
 * system here (see Api\Student\PlacementDriveController's docblock);
 * a drive is visible to a student purely by being PUBLISHED, and
 * hidden purely by not being PUBLISHED (DRAFT/CLOSED/CANCELLED all
 * behave identically from the student's point of view). Object-level
 * authorization for the detail route is enforced by
 * App\Policies\PlacementDrivePolicy.
 */
class PlacementDriveTest extends TestCase
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function drivePayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'title' => 'Campus Recruitment Drive 2026',
            'description' => 'Annual on-campus placement drive.',
            'drive_type' => 'CAMPUS_PLACEMENT',
            'venue' => 'Main Auditorium',
            'scheduled_at' => now()->addDays(10)->toIso8601String(),
            'application_deadline' => now()->addDays(5)->toIso8601String(),
        ], $overrides);
    }

    /**
     * Creates a drive in DRAFT via the real admin endpoint (never
     * inserted directly), optionally attaches one job, and optionally
     * drives it to PUBLISHED/CLOSED/CANCELLED through the real
     * lifecycle endpoints - so every fixture drive here is one the
     * admin surface could actually have produced.
     *
     * @return array{drive_id: int, job_posting: ?JobPosting}
     */
    private function makeDrive(string $status = 'DRAFT', array $overrides = [], bool $withJob = true): array
    {
        Sanctum::actingAs($this->admin, ['*']);
        $drive = $this->postJson('/api/admin/placement-drives', $this->drivePayload($overrides))
            ->assertCreated()
            ->json('data');

        $jobPosting = null;

        if ($withJob) {
            $jobPosting = $this->makeJobPosting();
            $this->postJson("/api/admin/placement-drives/{$drive['id']}/opportunities", [
                'opportunity_type' => 'job',
                'opportunity_id' => $jobPosting->id,
            ])->assertCreated();
        }

        if ($status !== 'DRAFT') {
            $this->postJson("/api/admin/placement-drives/{$drive['id']}/publish")->assertOk();
        }

        if ($status === 'CLOSED') {
            $this->postJson("/api/admin/placement-drives/{$drive['id']}/close")->assertOk();
        }

        if ($status === 'CANCELLED') {
            $this->postJson("/api/admin/placement-drives/{$drive['id']}/cancel", ['reason' => 'Test cancellation.'])->assertOk();
        }

        return ['drive_id' => $drive['id'], 'job_posting' => $jobPosting];
    }

    // --- visibility ---------------------------------------------------

    public function test_published_drive_is_visible_to_student(): void
    {
        $fixture = $this->makeDrive('PUBLISHED');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $response = $this->getJson('/api/student/placement-drives');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['id' => $fixture['drive_id']]);
    }

    public function test_draft_drive_is_hidden_from_student(): void
    {
        $this->makeDrive('DRAFT');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $response = $this->getJson('/api/student/placement-drives');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    public function test_closed_drive_is_hidden_from_student(): void
    {
        $this->makeDrive('CLOSED');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $response = $this->getJson('/api/student/placement-drives');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    public function test_cancelled_drive_is_hidden_from_student(): void
    {
        $this->makeDrive('CANCELLED');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $response = $this->getJson('/api/student/placement-drives');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    // --- detail ---------------------------------------------------------

    public function test_student_can_view_published_drive_detail(): void
    {
        $fixture = $this->makeDrive('PUBLISHED');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $response = $this->getJson("/api/student/placement-drives/{$fixture['drive_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['drive_id']);
        $response->assertJsonPath('data.status', 'PUBLISHED');
        $response->assertJsonCount(1, 'data.opportunities');
        $response->assertJsonPath('data.opportunities.0.application_status', null);
    }

    public function test_student_cannot_view_a_draft_drive(): void
    {
        $fixture = $this->makeDrive('DRAFT');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $this->getJson("/api/student/placement-drives/{$fixture['drive_id']}")->assertStatus(403);
    }

    public function test_student_cannot_view_a_cancelled_drive(): void
    {
        $fixture = $this->makeDrive('CANCELLED');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $this->getJson("/api/student/placement-drives/{$fixture['drive_id']}")->assertStatus(403);
    }

    public function test_drive_detail_reflects_students_own_application_status(): void
    {
        $fixture = $this->makeDrive('PUBLISHED');
        $student = $this->makeStudent();

        Sanctum::actingAs($student, ['*']);
        $this->postJson("/api/student/opportunities/jobs/{$fixture['job_posting']->id}/apply")->assertCreated();

        $response = $this->getJson("/api/student/placement-drives/{$fixture['drive_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.opportunities.0.application_status', 'APPLIED');
    }

    // --- field exposure ---------------------------------------------------

    public function test_student_response_does_not_expose_admin_or_internal_fields(): void
    {
        $fixture = $this->makeDrive('PUBLISHED');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $response = $this->getJson("/api/student/placement-drives/{$fixture['drive_id']}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.created_by');
        $response->assertJsonMissingPath('data.updated_by');
        $response->assertJsonMissingPath('data.creator');
        $response->assertJsonMissingPath('data.updater');
        $response->assertJsonMissingPath('data.cancelled_by');
        $response->assertJsonMissingPath('data.status_history');
        $response->assertJsonMissingPath('data.jobs');
        $response->assertJsonMissingPath('data.internships');
        $response->assertJsonMissingPath('data.application_count');
        $response->assertJsonMissingPath('data.selected_count');
        $response->assertJsonMissingPath('data.interview_count');
    }

    public function test_student_list_response_does_not_expose_admin_fields(): void
    {
        $this->makeDrive('PUBLISHED');

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $response = $this->getJson('/api/student/placement-drives');

        $response->assertOk();
        $response->assertJsonMissingPath('data.items.0.application_count');
        $response->assertJsonMissingPath('data.items.0.selected_count');
        $response->assertJsonMissingPath('data.items.0.created_at');
    }

    // --- security ---------------------------------------------------------

    public function test_guest_cannot_view_placement_drives(): void
    {
        $fixture = $this->makeDrive('PUBLISHED');

        // See Student\InterviewTest::test_guest_cannot_view_interviews()
        // for why this is required: makeDrive() leaves the auth guard
        // authenticated as $this->admin (it drives the real admin
        // endpoints), and Sanctum::actingAs() persists across requests
        // within a test until something re-authenticates or the guard
        // is explicitly reset.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/student/placement-drives')->assertStatus(401);
        $this->getJson("/api/student/placement-drives/{$fixture['drive_id']}")->assertStatus(401);
    }

    public function test_faculty_cannot_access_student_placement_drive_routes(): void
    {
        $roleModel = Role::where('name', RoleName::FACULTY->value)->firstOrFail();
        $faculty = User::create([
            'name' => 'Faculty User',
            'email' => 'faculty-'.uniqid().'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $roleModel->id,
        ]);

        Sanctum::actingAs($faculty, ['*']);
        $this->getJson('/api/student/placement-drives')->assertStatus(403);
    }
}
