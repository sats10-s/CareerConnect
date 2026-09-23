<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
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
 * GET/POST/PUT /api/admin/jobs[/{jobPosting}].
 * See App\Http\Controllers\Api\Admin\JobPostingController + JobPostingPolicy.
 */
class JobPostingTest extends TestCase
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
        $this->company = Company::create([
            'name' => 'NovaTech Solutions',
            'industry' => 'Software',
            'is_active' => true,
        ]);
    }

    private function makeUser(RoleName $role, array $overrides = []): User
    {
        $roleModel = Role::where('name', $role->value)->firstOrFail();

        return User::create(array_merge([
            'name' => 'Test User',
            'email' => strtolower($role->value).'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $roleModel->id,
        ], $overrides));
    }

    private function makeAdmin(): User
    {
        return $this->makeUser(RoleName::PLACEMENT_ADMIN);
    }

    private function makeStudent(): User
    {
        $user = $this->makeUser(RoleName::STUDENT);

        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
        ]);

        return $user;
    }

    private function makeFaculty(): User
    {
        $user = $this->makeUser(RoleName::FACULTY);

        Faculty::create([
            'user_id' => $user->id,
            'employee_code' => 'FAC001',
            'department_id' => $this->department->id,
        ]);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'title' => 'Software Engineer I',
            'description' => 'Build things.',
            'location' => 'Bengaluru',
            'salary_range' => '₹8-12 LPA',
            'employment_type' => 'full_time',
            'work_mode' => 'hybrid',
            'application_deadline' => now()->addMonth()->toDateString(),
            'openings' => 3,
            'status' => 'published',
            'eligibility_enforced' => true,
            'minimum_cgpa' => 7.5,
            'maximum_backlogs' => 1,
            'eligible_department_ids' => [$this->department->id],
            'eligible_batch_ids' => [$this->batch->id],
            'eligible_years' => [3, 4],
            'required_skills' => ['PHP', 'Laravel'],
        ], $overrides);
    }

    public function test_admin_can_create_a_job_posting(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.title', 'Software Engineer I');
        $response->assertJsonPath('data.company.name', 'NovaTech Solutions');
        $this->assertDatabaseHas('job_postings', ['title' => 'Software Engineer I', 'status' => 'published']);
    }

    public function test_eligibility_criteria_is_persisted_for_a_job_posting(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->validPayload());
        $response->assertCreated();

        $jobPosting = JobPosting::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligibility_criteria', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'eligibility_enforced' => true,
            'max_backlogs' => 1,
        ]);
        $this->assertDatabaseHas('opportunity_eligible_departments', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'department_id' => $this->department->id,
        ]);
        $this->assertDatabaseHas('opportunity_eligible_batches', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'batch_id' => $this->batch->id,
        ]);
        $this->assertDatabaseHas('opportunity_eligible_years', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'year' => 3,
        ]);
        $this->assertDatabaseHas('opportunity_required_skills', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'skill_name' => 'Laravel',
        ]);

        $response->assertJsonPath('data.eligibility.enforced', true);
        $response->assertJsonPath('data.eligibility.maximum_backlogs', 1);
        $response->assertJsonPath('data.eligibility.required_skills', ['PHP', 'Laravel']);
        $response->assertJsonPath('data.eligibility.departments.0.id', $this->department->id);
        $response->assertJsonPath('data.eligibility.departments.0.name', 'Computer Science & Engineering');
    }

    public function test_student_cannot_create_a_job_posting(): void
    {
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->postJson('/api/admin/jobs', $this->validPayload())->assertStatus(403);
    }

    public function test_faculty_cannot_create_a_job_posting(): void
    {
        Sanctum::actingAs($this->makeFaculty(), ['*']);

        $this->postJson('/api/admin/jobs', $this->validPayload())->assertStatus(403);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $this->postJson('/api/admin/jobs', $this->validPayload(['title' => '', 'company_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'company_id']);
    }

    public function test_show_returns_the_company_relationship(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $created = $this->postJson('/api/admin/jobs', $this->validPayload())->json('data');

        $response = $this->getJson("/api/admin/jobs/{$created['id']}");

        $response->assertOk();
        $response->assertJsonPath('data.company.id', $this->company->id);
        $response->assertJsonPath('data.company.name', 'NovaTech Solutions');
    }

    public function test_status_cannot_transition_from_closed_back_to_draft(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $created = $this->postJson('/api/admin/jobs', $this->validPayload(['status' => 'closed']))->json('data');

        $this->putJson("/api/admin/jobs/{$created['id']}", ['status' => 'draft'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_guest_cannot_manage_job_postings(): void
    {
        $this->postJson('/api/admin/jobs', $this->validPayload())->assertStatus(401);
    }
}
