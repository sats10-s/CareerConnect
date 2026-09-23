<?php

namespace Tests\Feature\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\RoleName;
use App\Models\Application;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/admin/applications[/{application}], PATCH
 * /api/admin/applications/{application}/status - see
 * Api\Admin\ApplicationController and App\Services\ApplicationService.
 */
class ApplicationTest extends TestCase
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

    private function makeFaculty(): User
    {
        $user = $this->makeUser(RoleName::FACULTY);

        Faculty::create([
            'user_id' => $user->id,
            'employee_code' => 'FAC'.random_int(1000, 9999),
            'department_id' => $this->department->id,
        ]);

        return $user;
    }

    private function makeStudentUser(): User
    {
        $user = $this->makeUser(RoleName::STUDENT);

        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24'.random_int(1000, 9999),
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 3,
            'cgpa' => 8.0,
            'backlogs' => 0,
        ]);

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
     * Creates an application by driving it through the real student
     * apply endpoint, so every test starts from a state the app itself
     * can actually produce (initial APPLIED history included) rather
     * than a hand-built row.
     */
    private function createApplication(JobPosting $jobPosting): Application
    {
        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $data = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data');

        return Application::findOrFail($data['id']);
    }

    public function test_admin_can_list_applications(): void
    {
        $jobPosting = $this->makeJobPosting();
        $this->createApplication($jobPosting);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->getJson('/api/admin/applications');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['title' => 'Software Engineer I']);
    }

    public function test_admin_can_view_application_detail(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->getJson("/api/admin/applications/{$application->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $application->id);
        $response->assertJsonPath('data.status', 'APPLIED');
        $response->assertJsonCount(1, 'data.status_history');
        $response->assertJsonStructure(['data' => ['student' => ['name', 'roll_number', 'department']]]);
    }

    public function test_admin_can_update_application_status(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->patchJson("/api/admin/applications/{$application->id}/status", [
            'status' => 'UNDER_REVIEW',
            'remarks' => 'Screening resume.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'UNDER_REVIEW']);
    }

    public function test_valid_transition_succeeds(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);
        $application->update(['status' => ApplicationStatus::UNDER_REVIEW]);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'SHORTLISTED']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SHORTLISTED');
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);
        // APPLIED -> SELECTED is not a supported transition.

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'SELECTED']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'APPLIED']);
    }

    public function test_admin_cannot_set_status_to_withdrawn(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'WITHDRAWN']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_status_change_creates_a_history_record(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->patchJson("/api/admin/applications/{$application->id}/status", [
            'status' => 'UNDER_REVIEW',
            'remarks' => 'Screening resume.',
        ])->assertOk();

        $this->assertDatabaseHas('application_status_history', [
            'application_id' => $application->id,
            'from_status' => 'APPLIED',
            'to_status' => 'UNDER_REVIEW',
            'remarks' => 'Screening resume.',
        ]);
        $this->assertSame(2, $application->statusHistory()->count());
    }

    public function test_selected_at_and_rejection_reason_are_recorded_correctly(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);
        $application->update(['status' => ApplicationStatus::UNDER_REVIEW]);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'SHORTLISTED'])->assertOk();
        $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'ASSESSMENT'])->assertOk();
        $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'INTERVIEW'])->assertOk();
        $response = $this->patchJson("/api/admin/applications/{$application->id}/status", [
            'status' => 'SELECTED',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.selected_at'));

        $application->refresh();
        $this->assertNotNull($application->selected_at);
        $this->assertNull($application->rejection_reason);
    }

    public function test_student_cannot_update_application_status(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);

        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'UNDER_REVIEW'])
            ->assertStatus(403);
    }

    public function test_faculty_cannot_update_application_status(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->createApplication($jobPosting);

        Sanctum::actingAs($this->makeFaculty(), ['*']);
        $this->patchJson("/api/admin/applications/{$application->id}/status", ['status' => 'UNDER_REVIEW'])
            ->assertStatus(403);
    }

    public function test_unauthorized_users_cannot_access_admin_applications(): void
    {
        $this->getJson('/api/admin/applications')->assertStatus(401);

        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $this->getJson('/api/admin/applications')->assertStatus(403);
    }
}
