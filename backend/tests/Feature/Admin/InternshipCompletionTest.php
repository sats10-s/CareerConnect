<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/admin/internship-completions[...] and
 * GET /api/admin/ppo-records[...] - see Api\Admin\InternshipCompletionController/
 * PpoRecordController. There is deliberately no admin create/update/
 * finalize test here for either resource - neither admin controller has
 * a write action at all (see their own docblocks and the module spec's
 * Admin API list, which is GET-only for both).
 */
class InternshipCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Batch $batch;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
        $this->company = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);

        Storage::fake('local');
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

    private function makeAdmin(): User
    {
        return $this->makeUser(RoleName::PLACEMENT_ADMIN);
    }

    private function makeSuperAdmin(): User
    {
        return $this->makeUser(RoleName::SUPER_ADMIN);
    }

    private function makeFaculty(array $overrides = []): Faculty
    {
        $user = $this->makeUser(RoleName::FACULTY, array_intersect_key($overrides, array_flip(['email'])));

        return Faculty::create(array_merge([
            'user_id' => $user->id,
            'employee_code' => 'FAC'.random_int(1000, 9999),
            'department_id' => $this->department->id,
        ], array_diff_key($overrides, array_flip(['email']))));
    }

    private function makeStudentUser(array $overrides = []): User
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

    private function makeInternship(array $overrides = []): Internship
    {
        return Internship::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Backend Intern',
            'status' => 'published',
        ], $overrides));
    }

    private function selectApplication(User $admin, User $studentUser, Internship $internship): int
    {
        Sanctum::actingAs($studentUser, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply")
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($admin, ['*']);
        foreach (['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED'] as $status) {
            $this->patchJson("/api/admin/applications/{$applicationId}/status", ['status' => $status])->assertOk();
        }

        return $applicationId;
    }

    /**
     * @return array{admin: User, faculty: Faculty, student: User, internship: Internship, application_id: int, assignment_id: int}
     */
    private function activatedFixture(): array
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        Sanctum::actingAs($admin, ['*']);
        $assignmentId = $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/internship-assignments/{$assignmentId}/activate")->assertOk();

        return [
            'admin' => $admin,
            'faculty' => $faculty,
            'student' => $studentUser,
            'internship' => $internship,
            'application_id' => $applicationId,
            'assignment_id' => $assignmentId,
        ];
    }

    private function completionFixture(): array
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $completion = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ])->assertCreated()->json('data');

        $fixture['completion_id'] = $completion['id'];

        return $fixture;
    }

    private function ppoFixture(): array
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $ppo = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
            'package' => 1200000,
        ])->assertCreated()->json('data');

        $fixture['ppo_id'] = $ppo['id'];

        return $fixture;
    }

    // --- Completion: list / view -------------------------------------------------

    public function test_admin_can_list_all_completions(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/internship-completions');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_completions_by_status_and_outcome(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->getJson('/api/admin/internship-completions?status=DRAFT')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/admin/internship-completions?status=FINALIZED')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/admin/internship-completions?outcome=COMPLETED')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/admin/internship-completions?outcome=NOT_COMPLETED')->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_admin_can_view_completion_detail(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/internship-completions/{$fixture['completion_id']}");

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number'],
            'faculty' => ['id', 'name'],
            'internship',
            'completed_by',
            'history',
        ]]);
    }

    public function test_super_admin_can_view_completions(): void
    {
        $fixture = $this->completionFixture();
        $superAdmin = $this->makeSuperAdmin();

        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/admin/internship-completions')->assertOk();
        $this->getJson("/api/admin/internship-completions/{$fixture['completion_id']}")->assertOk();
    }

    public function test_admin_cannot_create_update_or_finalize_completions(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ])->assertStatus(403);
    }

    // --- PPO: list / view ----------------------------------------------------------

    public function test_admin_can_list_all_ppo_records(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/ppo-records');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_ppo_records_by_status_and_company(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->getJson('/api/admin/ppo-records?status=OFFERED')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/admin/ppo-records?status=ACCEPTED')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/admin/ppo-records?company_id={$this->company->id}")->assertOk()->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_view_ppo_record_detail(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/ppo-records/{$fixture['ppo_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['ppo_id']);
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number'],
            'company',
            'history',
        ]]);
    }

    public function test_admin_cannot_create_or_update_ppo_records(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
        ])->assertStatus(403);
    }

    public function test_ppo_record_never_exposes_a_write_route_for_admin(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED'])->assertStatus(403);
    }

    // --- security -------------------------------------------------------------

    public function test_student_cannot_access_admin_completion_or_ppo_routes(): void
    {
        $completion = $this->completionFixture();

        Sanctum::actingAs($completion['student'], ['*']);
        $this->getJson('/api/admin/internship-completions')->assertStatus(403);
        $this->getJson("/api/admin/internship-completions/{$completion['completion_id']}")->assertStatus(403);
        $this->getJson('/api/admin/ppo-records')->assertStatus(403);
    }

    public function test_faculty_cannot_access_admin_completion_or_ppo_routes(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson('/api/admin/internship-completions')->assertStatus(403);
        $this->getJson('/api/admin/ppo-records')->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_completion_or_ppo_routes(): void
    {
        $fixture = $this->completionFixture();

        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/admin/internship-completions')->assertStatus(401);
        $this->getJson("/api/admin/internship-completions/{$fixture['completion_id']}")->assertStatus(401);
        $this->getJson('/api/admin/ppo-records')->assertStatus(401);
    }
}
