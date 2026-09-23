<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin-controlled eligibility configuration via the existing job/
 * internship create/update contract - see
 * App\Http\Controllers\Concerns\BuildsEligibilityPayload and
 * App\Models\Concerns\HasEligibilityCriteria::syncEligibility(). Each
 * criterion is exercised on its own, with the others left unspecified,
 * to prove "an unspecified criterion is unrestricted" holds
 * per-dimension - Admin\JobPostingTest/InternshipTest's own eligibility
 * test already covers configuring every dimension together.
 */
class OpportunityEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Department $cse;

    private Department $it;

    private Batch $batch2022;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->cse = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->it = Department::create(['name' => 'Information Technology', 'code' => 'IT']);
        $this->batch2022 = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
        $this->company = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);
    }

    private function makeUser(RoleName $role): User
    {
        $roleModel = Role::where('name', $role->value)->firstOrFail();

        return User::create([
            'name' => 'Test User',
            'email' => strtolower($role->value).'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $roleModel->id,
        ]);
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
            'department_id' => $this->cse->id,
            'batch_id' => $this->batch2022->id,
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
            'department_id' => $this->cse->id,
        ]);

        return $user;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'title' => 'Software Engineer I',
            'status' => 'published',
        ], $overrides);
    }

    public function test_admin_can_configure_department_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->basePayload([
            'eligible_department_ids' => [$this->cse->id],
        ]));

        $response->assertCreated();
        $jobPosting = JobPosting::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligible_departments', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'department_id' => $this->cse->id,
        ]);
        $this->assertDatabaseMissing('opportunity_eligible_departments', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'department_id' => $this->it->id,
        ]);
        // Batches were never specified for this posting - unrestricted.
        $this->assertDatabaseMissing('opportunity_eligible_batches', ['opportunity_id' => $jobPosting->id]);
        $response->assertJsonPath('data.eligibility.departments.0.id', $this->cse->id);
        $response->assertJsonPath('data.eligibility.batches', []);
    }

    public function test_admin_can_configure_batch_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->basePayload([
            'eligible_batch_ids' => [$this->batch2022->id],
        ]));

        $response->assertCreated();
        $jobPosting = JobPosting::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligible_batches', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'batch_id' => $this->batch2022->id,
        ]);
        $response->assertJsonPath('data.eligibility.batches.0.id', $this->batch2022->id);
        $response->assertJsonPath('data.eligibility.departments', []);
    }

    public function test_admin_can_configure_year_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->basePayload([
            'eligible_years' => [3, 4],
        ]));

        $response->assertCreated();
        $jobPosting = JobPosting::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligible_years', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'year' => 3,
        ]);
        $this->assertDatabaseHas('opportunity_eligible_years', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'year' => 4,
        ]);
        $response->assertJsonPath('data.eligibility.years', [3, 4]);
    }

    public function test_admin_can_configure_cgpa_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->basePayload([
            'minimum_cgpa' => 8.25,
        ]));

        $response->assertCreated();
        $jobPosting = JobPosting::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligibility_criteria', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'min_cgpa' => 8.25,
        ]);
        $response->assertJsonPath('data.eligibility.minimum_cgpa', 8.25);
    }

    public function test_admin_can_configure_backlog_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->basePayload([
            'maximum_backlogs' => 0,
        ]));

        $response->assertCreated();
        $jobPosting = JobPosting::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligibility_criteria', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'max_backlogs' => 0,
        ]);
        $response->assertJsonPath('data.eligibility.maximum_backlogs', 0);
    }

    public function test_admin_can_configure_required_skills(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/jobs', $this->basePayload([
            'required_skills' => ['Java', ' java ', 'JAVA', 'SQL'],
        ]));

        $response->assertCreated();
        $jobPosting = JobPosting::firstOrFail();

        // Trimmed, whitespace-collapsed, and deduplicated case-insensitively -
        // three case/whitespace variants of "java" collapse to one row.
        $this->assertSame(1, DB::table('opportunity_required_skills')
            ->where('opportunity_type', JobPosting::class)
            ->where('opportunity_id', $jobPosting->id)
            ->where('skill_name', 'Java')
            ->count());
        $this->assertDatabaseHas('opportunity_required_skills', [
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
            'skill_name' => 'SQL',
        ]);
        $response->assertJsonPath('data.eligibility.required_skills', ['Java', 'SQL']);
    }

    public function test_admin_can_configure_internship_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $response = $this->postJson('/api/admin/internships', [
            'company_id' => $this->company->id,
            'title' => 'Backend Intern',
            'status' => 'published',
            'eligibility_enforced' => true,
            'minimum_cgpa' => 7.0,
            'required_skills' => ['Python'],
        ]);

        $response->assertCreated();
        $internship = Internship::firstOrFail();

        $this->assertDatabaseHas('opportunity_eligibility_criteria', [
            'opportunity_type' => Internship::class,
            'opportunity_id' => $internship->id,
            'eligibility_enforced' => true,
            'min_cgpa' => 7.0,
        ]);
        $response->assertJsonPath('data.eligibility.enforced', true);
    }

    public function test_updating_eligibility_replaces_previous_criteria_atomically(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $created = $this->postJson('/api/admin/jobs', $this->basePayload([
            'eligible_department_ids' => [$this->cse->id],
            'required_skills' => ['PHP'],
        ]))->json('data');

        $response = $this->putJson("/api/admin/jobs/{$created['id']}", [
            'eligible_department_ids' => [$this->it->id],
            'required_skills' => ['Java', 'SQL'],
        ]);

        $response->assertOk();
        $jobPosting = JobPosting::findOrFail($created['id']);

        $this->assertDatabaseMissing('opportunity_eligible_departments', [
            'opportunity_id' => $jobPosting->id,
            'department_id' => $this->cse->id,
        ]);
        $this->assertDatabaseHas('opportunity_eligible_departments', [
            'opportunity_id' => $jobPosting->id,
            'department_id' => $this->it->id,
        ]);
        $this->assertDatabaseMissing('opportunity_required_skills', [
            'opportunity_id' => $jobPosting->id,
            'skill_name' => 'PHP',
        ]);
        $response->assertJsonPath('data.eligibility.required_skills', ['Java', 'SQL']);
    }

    public function test_updating_eligibility_leaves_unmentioned_criteria_untouched(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $created = $this->postJson('/api/admin/jobs', $this->basePayload([
            'eligible_department_ids' => [$this->cse->id],
            'minimum_cgpa' => 7.5,
        ]))->json('data');

        // Only maximum_backlogs is sent - department and minimum_cgpa were
        // never mentioned, so they must be left exactly as they were.
        $response = $this->putJson("/api/admin/jobs/{$created['id']}", [
            'maximum_backlogs' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.eligibility.minimum_cgpa', 7.5);
        $response->assertJsonPath('data.eligibility.maximum_backlogs', 1);
        $response->assertJsonPath('data.eligibility.departments.0.id', $this->cse->id);
    }

    public function test_student_cannot_modify_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $created = $this->postJson('/api/admin/jobs', $this->basePayload())->json('data');

        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->putJson("/api/admin/jobs/{$created['id']}", [
            'eligible_department_ids' => [$this->it->id],
        ])->assertStatus(403);

        $this->postJson('/api/admin/jobs', $this->basePayload([
            'eligible_department_ids' => [$this->it->id],
        ]))->assertStatus(403);
    }

    public function test_faculty_cannot_modify_eligibility(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $created = $this->postJson('/api/admin/jobs', $this->basePayload())->json('data');

        Sanctum::actingAs($this->makeFaculty(), ['*']);

        $this->putJson("/api/admin/jobs/{$created['id']}", [
            'eligible_department_ids' => [$this->it->id],
        ])->assertStatus(403);
    }
}
