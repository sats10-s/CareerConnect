<?php

namespace Tests\Feature\Student;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student-facing eligibility: the informational `my_eligibility` block
 * merged into GET /api/student/opportunities/jobs/{jobPosting} (and the
 * mirrored internships route) - see Api\Student\OpportunityController
 * and App\Services\OpportunityEligibilityService.
 *
 * test_published_opportunity_remains_visible_to_an_ineligible_student()
 * guards the hard product rule that eligibility NEVER filters the
 * listing - everything else here exercises OpportunityEligibilityService
 * through the detail endpoint, one failure dimension at a time, mirroring
 * Admin\OpportunityEligibilityTest's per-dimension structure.
 */
class OpportunityEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Department $cse;

    private Department $it;

    private Batch $batch2022;

    private Batch $batch2023;

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
        $this->batch2023 = Batch::create(['name' => '2023-2027', 'start_year' => 2023, 'end_year' => 2027]);
        $this->company = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);
    }

    /**
     * @param array<string, mixed> $overrides student column overrides;
     *   'skills' (string[]) creates matching StudentSkill rows.
     */
    private function makeStudent(array $overrides = []): User
    {
        $roleModel = Role::where('name', RoleName::STUDENT->value)->firstOrFail();
        $skills = $overrides['skills'] ?? null;
        unset($overrides['skills']);

        $user = User::create([
            'name' => 'Test Student',
            'email' => $overrides['email'] ?? 'student@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $roleModel->id,
        ]);
        unset($overrides['email']);

        $student = Student::create(array_merge([
            'user_id' => $user->id,
            'roll_number' => $overrides['roll_number'] ?? 'CC24001',
            'department_id' => $this->cse->id,
            'batch_id' => $this->batch2022->id,
            'year' => 3,
            'cgpa' => 8.0,
            'backlogs' => 0,
        ], $overrides));
        unset($overrides['roll_number']);

        if ($skills !== null) {
            foreach ($skills as $skillName) {
                StudentSkill::create([
                    'student_id' => $student->id,
                    'skill_name' => $skillName,
                ]);
            }
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $eligibility passed straight to
     *   syncEligibility() when non-empty - this is the model's own
     *   internal shape (eligibility_enforced/min_cgpa/max_backlogs/
     *   department_ids/batch_ids/years/skills), NOT the flat HTTP
     *   request field names BuildsEligibilityPayload translates from.
     * @param array<string, mixed> $overrides JobPosting column overrides.
     */
    private function makeJobPosting(array $eligibility = [], array $overrides = []): JobPosting
    {
        $jobPosting = JobPosting::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Software Engineer I',
            'status' => 'published',
        ], $overrides));

        if ($eligibility !== []) {
            $jobPosting->syncEligibility($eligibility);
        }

        return $jobPosting;
    }

    public function test_published_opportunity_remains_visible_to_an_ineligible_student(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'department_ids' => [$this->it->id],
        ]);

        // Student is CSE, not IT - would fail eligibility, but the
        // listing must never filter, sort, or hide based on it.
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson('/api/student/opportunities');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $jobPosting->id, 'title' => 'Software Engineer I']);
    }

    public function test_eligible_student_receives_eligible_true(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'department_ids' => [$this->cse->id],
        ]);

        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligibility_enforced', true);
        $response->assertJsonPath('data.my_eligibility.eligible', true);
        $response->assertJsonPath('data.my_eligibility.reasons', []);
    }

    public function test_ineligible_department_produces_department_reason(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'department_ids' => [$this->it->id],
        ]);

        Sanctum::actingAs($this->makeStudent(['department_id' => $this->cse->id]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'DEPARTMENT']);
    }

    public function test_ineligible_batch_produces_batch_reason(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'batch_ids' => [$this->batch2023->id],
        ]);

        Sanctum::actingAs($this->makeStudent(['batch_id' => $this->batch2022->id]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'BATCH']);
    }

    public function test_ineligible_year_produces_year_reason(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'years' => [4],
        ]);

        Sanctum::actingAs($this->makeStudent(['year' => 2]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'YEAR']);
    }

    public function test_low_cgpa_produces_cgpa_reason(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'min_cgpa' => 7.5,
        ]);

        Sanctum::actingAs($this->makeStudent(['cgpa' => 6.9]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'CGPA', 'message' => 'Minimum CGPA required is 7.50.']);
    }

    public function test_excessive_backlogs_produces_backlogs_reason(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'max_backlogs' => 0,
        ]);

        Sanctum::actingAs($this->makeStudent(['backlogs' => 2]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'BACKLOGS', 'message' => 'Maximum allowed backlogs is 0.']);
    }

    public function test_missing_cgpa_is_not_eligible_and_says_why(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'min_cgpa' => 6.0,
            'max_backlogs' => 2,
        ]);

        // Never treated as 0 - and never as a pass either.
        Sanctum::actingAs($this->makeStudent(['cgpa' => null, 'backlogs' => null]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'CGPA', 'message' => 'Minimum CGPA required is 6.00, and your CGPA is not yet available from the college.']);
        $response->assertJsonFragment(['code' => 'BACKLOGS', 'message' => 'Maximum allowed backlogs is 2, and your backlogs have not been reported by the college.']);
    }

    public function test_missing_required_skill_produces_skills_reason(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'skills' => ['Java', 'SQL'],
        ]);

        Sanctum::actingAs($this->makeStudent(['skills' => ['Java']]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'SKILLS']);
    }

    public function test_multiple_failed_criteria_return_multiple_reasons(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'min_cgpa' => 8.5,
            'max_backlogs' => 0,
        ]);

        Sanctum::actingAs($this->makeStudent(['cgpa' => 6.0, 'backlogs' => 3]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $reasons = collect($response->json('data.my_eligibility.reasons'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['CGPA', 'BACKLOGS'], $reasons);
    }

    public function test_missing_student_profile_data_fails_safely_when_enforced(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'min_cgpa' => 7.0,
        ]);

        // No CGPA on file at all - must fail, never be treated as
        // silently satisfying the minimum.
        Sanctum::actingAs($this->makeStudent(['cgpa' => null]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'CGPA']);
    }

    public function test_eligibility_enforced_false_does_not_block_the_student(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => false,
            'department_ids' => [$this->it->id],
            'min_cgpa' => 9.9,
        ]);

        // Every configured criterion would fail, but enforcement is off.
        Sanctum::actingAs($this->makeStudent(['department_id' => $this->cse->id, 'cgpa' => 5.0]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligibility_enforced', false);
        $response->assertJsonPath('data.my_eligibility.eligible', true);
        $response->assertJsonPath('data.my_eligibility.reasons', []);
    }

    public function test_required_skills_use_all_semantics(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'skills' => ['Java', 'SQL', 'Docker'],
        ]);

        // Has two of the three required skills - ALL must be present,
        // so this is still ineligible.
        Sanctum::actingAs($this->makeStudent(['skills' => ['Java', 'SQL']]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', false);
        $response->assertJsonFragment(['code' => 'SKILLS']);
    }

    public function test_skill_comparison_is_case_insensitive_and_whitespace_normalized(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'skills' => ['Java', 'SQL'],
        ]);

        // Differently-cased, whitespace-padded variants of the same
        // skill names must still satisfy the requirement.
        Sanctum::actingAs($this->makeStudent(['skills' => [' java ', 'sql']]), ['*']);

        $response = $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}");

        $response->assertOk();
        $response->assertJsonPath('data.my_eligibility.eligible', true);
        $response->assertJsonPath('data.my_eligibility.reasons', []);
    }
}
