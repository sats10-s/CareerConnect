<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET/POST/PUT /api/admin/internships[/{internship}].
 * See App\Http\Controllers\Api\Admin\InternshipController + InternshipPolicy.
 * Mirrors Admin\JobPostingTest - see its docblock for the shared reasoning.
 */
class InternshipTest extends TestCase
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
            'name' => 'Vertex Cloud Works',
            'industry' => 'DevOps & Cloud',
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'title' => 'DevOps Intern',
            'description' => 'Ship pipelines.',
            'location' => 'Remote',
            'duration_months' => 6,
            'stipend' => '₹25,000/month',
            'work_mode' => 'remote',
            'application_deadline' => now()->addMonth()->toDateString(),
            'openings' => 2,
            'status' => 'published',
            'eligibility' => [
                'min_cgpa' => 6.5,
                'max_backlogs' => 2,
                'department_ids' => [$this->department->id],
                'batch_ids' => [$this->batch->id],
                'years' => [2, 3],
                'skills' => ['Docker', 'Linux'],
            ],
        ], $overrides);
    }

    public function test_admin_can_create_an_internship(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/internships', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.title', 'DevOps Intern');
        $response->assertJsonPath('data.company.name', 'Vertex Cloud Works');
        $this->assertDatabaseHas('internships', ['title' => 'DevOps Intern', 'status' => 'published']);
    }

    public function test_eligibility_criteria_is_persisted_for_an_internship(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $this->postJson('/api/admin/internships', $this->validPayload())->assertCreated();

        $internship = Internship::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligibility_criteria', [
            'opportunity_type' => Internship::class,
            'opportunity_id' => $internship->id,
            'max_backlogs' => 2,
        ]);
        $this->assertDatabaseHas('opportunity_required_skills', [
            'opportunity_type' => Internship::class,
            'opportunity_id' => $internship->id,
            'skill_name' => 'Docker',
        ]);
    }

    public function test_student_cannot_create_an_internship(): void
    {
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->postJson('/api/admin/internships', $this->validPayload())->assertStatus(403);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $this->postJson('/api/admin/internships', $this->validPayload(['title' => '', 'duration_months' => 999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'duration_months']);
    }

    public function test_guest_cannot_manage_internships(): void
    {
        $this->postJson('/api/admin/internships', $this->validPayload())->assertStatus(401);
    }
}
