<?php

namespace Tests\Feature\Student;

use App\Enums\ApplicationStatus;
use App\Enums\RoleName;
use App\Models\Application;
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
 * POST /api/student/opportunities/{jobs|internships}/{id}/apply (see
 * Api\Student\OpportunityController::applyToJob/applyToInternship) and
 * GET/POST /api/student/applications[...] (see
 * Api\Student\ApplicationController) - the Application Lifecycle
 * module's student-facing surface. See App\Services\ApplicationService
 * for the actual business rules exercised here.
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
            'placement_status' => 'NOT_PLACED',
        ], array_diff_key($overrides, array_flip(['email']))));

        return $user;
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

    /**
     * @param array<string, mixed> $eligibility internal syncEligibility() shape.
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

    private function makeInternship(array $eligibility = [], array $overrides = []): Internship
    {
        $internship = Internship::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Backend Intern',
            'status' => 'published',
        ], $overrides));

        if ($eligibility !== []) {
            $internship->syncEligibility($eligibility);
        }

        return $internship;
    }

    public function test_student_can_apply_to_a_published_job(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply");

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'APPLIED');
        $response->assertJsonPath('data.opportunity.type', 'job');
        $this->assertDatabaseHas('applications', [
            'applicable_type' => JobPosting::class,
            'applicable_id' => $jobPosting->id,
            'status' => 'APPLIED',
        ]);
    }

    public function test_student_can_apply_to_a_published_internship(): void
    {
        $internship = $this->makeInternship();
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply");

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'APPLIED');
        $response->assertJsonPath('data.opportunity.type', 'internship');
        $this->assertDatabaseHas('applications', [
            'applicable_type' => Internship::class,
            'applicable_id' => $internship->id,
            'status' => 'APPLIED',
        ]);
    }

    public function test_placed_student_cannot_apply_by_default_to_a_job_or_internship(): void
    {
        $job = $this->makeJobPosting();
        $internship = $this->makeInternship();
        $student = $this->makeStudent(['placement_status' => 'PLACED']);
        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$job->id}/apply")
            ->assertUnprocessable()->assertJsonFragment(['code' => 'PLACEMENT_RESTRICTED']);
        $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply")
            ->assertUnprocessable()->assertJsonFragment(['code' => 'PLACEMENT_RESTRICTED']);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_individual_exception_applies_only_to_its_student_and_opportunity(): void
    {
        $job = $this->makeJobPosting();
        $otherJob = $this->makeJobPosting(overrides: ['title' => 'Another role']);
        $placed = $this->makeStudent(['placement_status' => 'PLACED']);
        $otherPlaced = $this->makeStudent(['placement_status' => 'PLACED']);
        DB::table('student_opportunity_placement_exceptions')->insert([
            'student_id' => $placed->student->id, 'opportunity_type' => JobPosting::class, 'opportunity_id' => $job->id,
            'grant_reason' => 'Approved by the placement office.', 'granted_by' => null, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($placed, ['*']);
        $this->postJson("/api/student/opportunities/jobs/{$job->id}/apply")->assertCreated();
        $this->postJson("/api/student/opportunities/jobs/{$otherJob->id}/apply")
            ->assertUnprocessable()->assertJsonFragment(['code' => 'PLACEMENT_RESTRICTED']);
        Sanctum::actingAs($otherPlaced, ['*']);
        $this->postJson("/api/student/opportunities/jobs/{$job->id}/apply")
            ->assertUnprocessable()->assertJsonFragment(['code' => 'PLACEMENT_RESTRICTED']);
    }

    public function test_admin_exception_grant_and_revocation_keep_auditable_history(): void
    {
        $student = $this->makeStudent(['placement_status' => 'PLACED']);
        $job = $this->makeJobPosting();
        $admin = $this->makeUser(RoleName::PLACEMENT_ADMIN);
        Sanctum::actingAs($admin, ['*']);

        $id = $this->postJson("/api/admin/placement-eligibility/opportunities/job/{$job->id}/exceptions", [
            'student_id' => $student->student->id, 'reason' => 'Approved after placement office review.',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/placement-eligibility/exceptions/{$id}/revoke", ['reason' => 'Permission no longer needed.'])->assertOk();

        $this->assertDatabaseHas('student_opportunity_placement_exceptions', [
            'id' => $id, 'granted_by' => $admin->id, 'revoked_by' => $admin->id,
            'revocation_reason' => 'Permission no longer needed.',
        ]);
    }

    public function test_student_cannot_grant_placement_exception(): void
    {
        $student = $this->makeStudent(['placement_status' => 'NOT_PLACED']);
        $job = $this->makeJobPosting();
        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/admin/placement-eligibility/opportunities/job/{$job->id}/exceptions", [
            'student_id' => $student->student->id, 'reason' => 'Not allowed.',
        ])->assertForbidden();
    }

    public function test_cannot_apply_to_a_draft_opportunity(): void
    {
        $jobPosting = $this->makeJobPosting(overrides: ['status' => 'draft']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertStatus(403);

        $this->assertDatabaseMissing('applications', [
            'applicable_type' => JobPosting::class,
            'applicable_id' => $jobPosting->id,
        ]);
    }

    public function test_cannot_apply_to_a_closed_opportunity(): void
    {
        $jobPosting = $this->makeJobPosting(overrides: ['status' => 'closed']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertStatus(403);

        $this->assertDatabaseMissing('applications', [
            'applicable_type' => JobPosting::class,
            'applicable_id' => $jobPosting->id,
        ]);
    }

    public function test_cannot_apply_twice_to_the_same_opportunity(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertCreated();
        $second = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply");

        $second->assertStatus(422);
        $second->assertJsonPath('success', false);
        $this->assertSame(1, Application::where('applicable_type', JobPosting::class)
            ->where('applicable_id', $jobPosting->id)
            ->count());
    }

    public function test_eligibility_enforced_false_allows_application(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => false,
            'min_cgpa' => 9.9,
        ]);

        // Would fail the (informational-only) CGPA criterion.
        Sanctum::actingAs($this->makeStudent(['cgpa' => 5.0]), ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertCreated();
    }

    public function test_eligible_student_can_apply_when_enforcement_is_on(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'min_cgpa' => 7.0,
        ]);

        Sanctum::actingAs($this->makeStudent(['cgpa' => 8.5]), ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertCreated();
    }

    public function test_ineligible_student_cannot_apply_when_enforcement_is_on(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'min_cgpa' => 8.0,
        ]);

        Sanctum::actingAs($this->makeStudent(['cgpa' => 5.0]), ['*']);

        $response = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply");

        $response->assertStatus(422);
        $response->assertJsonFragment(['code' => 'CGPA']);
        $this->assertDatabaseMissing('applications', [
            'applicable_type' => JobPosting::class,
            'applicable_id' => $jobPosting->id,
        ]);
    }

    public function test_missing_student_profile_data_fails_safely_when_enforcement_is_on(): void
    {
        $jobPosting = $this->makeJobPosting([
            'eligibility_enforced' => true,
            'min_cgpa' => 7.0,
        ]);

        Sanctum::actingAs($this->makeStudent(['cgpa' => null]), ['*']);

        $response = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply");

        $response->assertStatus(422);
        $response->assertJsonFragment(['code' => 'CGPA']);
    }

    public function test_application_creates_an_applied_history_record(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertCreated();

        $application = Application::firstOrFail();

        $this->assertDatabaseHas('application_status_history', [
            'application_id' => $application->id,
            'from_status' => null,
            'to_status' => 'APPLIED',
        ]);
        $this->assertSame(1, $application->statusHistory()->count());
    }

    public function test_guest_cannot_apply(): void
    {
        $jobPosting = $this->makeJobPosting();

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertStatus(401);
    }

    public function test_non_student_cannot_apply(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeFaculty(), ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertStatus(403);
    }

    public function test_student_sees_own_applications(): void
    {
        $jobPosting = $this->makeJobPosting();
        $student = $this->makeStudent();
        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertCreated();

        $response = $this->getJson('/api/student/applications');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['id' => $jobPosting->id, 'title' => 'Software Engineer I']);
    }

    public function test_student_cannot_see_another_students_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(['email' => 'owner@careerconnect.edu']), ['*']);
        $created = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data');

        Sanctum::actingAs($this->makeStudent(['email' => 'intruder@careerconnect.edu']), ['*']);
        $this->getJson("/api/student/applications/{$created['id']}")->assertStatus(403);
    }

    public function test_student_can_view_own_application_detail(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(), ['*']);
        $created = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data');

        $response = $this->getJson("/api/student/applications/{$created['id']}");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPLIED');
        $response->assertJsonPath('data.opportunity.id', $jobPosting->id);
        $response->assertJsonCount(1, 'data.status_history');
        $response->assertJsonPath('data.my_eligibility.eligible', true);
    }

    public function test_student_can_withdraw_an_eligible_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(), ['*']);
        $created = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data');

        $response = $this->postJson("/api/student/applications/{$created['id']}/withdraw", ['remarks' => 'Found another offer.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'WITHDRAWN');
        $this->assertNotNull($response->json('data.withdrawn_at'));
        $this->assertDatabaseHas('applications', ['id' => $created['id'], 'status' => 'WITHDRAWN']);
    }

    public function test_student_cannot_withdraw_a_terminal_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(), ['*']);
        $created = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data');

        $application = Application::findOrFail($created['id']);
        $application->update(['status' => ApplicationStatus::REJECTED]);

        $response = $this->postJson("/api/student/applications/{$created['id']}/withdraw");

        $response->assertStatus(422);
        $this->assertDatabaseHas('applications', ['id' => $created['id'], 'status' => 'REJECTED']);
    }

    public function test_withdrawal_creates_a_history_record(): void
    {
        $jobPosting = $this->makeJobPosting();
        Sanctum::actingAs($this->makeStudent(), ['*']);
        $created = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data');

        $this->postJson("/api/student/applications/{$created['id']}/withdraw", ['remarks' => 'Changed my mind.'])
            ->assertOk();

        $this->assertDatabaseHas('application_status_history', [
            'application_id' => $created['id'],
            'from_status' => 'APPLIED',
            'to_status' => 'WITHDRAWN',
            'remarks' => 'Changed my mind.',
        ]);
        $this->assertSame(2, Application::findOrFail($created['id'])->statusHistory()->count());
    }

    public function test_duplicate_application_is_prevented_at_the_database_level(): void
    {
        $jobPosting = $this->makeJobPosting();
        $student = $this->makeStudent();

        $row = [
            'student_id' => $student->student->id,
            'applicable_type' => JobPosting::class,
            'applicable_id' => $jobPosting->id,
            'status' => 'APPLIED',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('applications')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('applications')->insert($row);
    }
}
