<?php

namespace Tests\Feature\Integration;

use App\Enums\ApplicationStatus;
use App\Enums\RoleName;
use App\Models\Application;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-module integration for Placement Drive Management: confirms
 * the drive stays a thin container over the EXISTING
 * JobPosting/Internship -> Application -> Interview chain rather than
 * a second parallel system (see the module's architecture rule -
 * Company -> Placement Drive -> Job/Internship -> Applications ->
 * Interviews -> Selections). Nothing here inserts directly into
 * applications/interviews - every fixture is produced through the
 * real student/admin endpoints, exactly like
 * Admin\PlacementDriveTest/Student\PlacementDriveTest.
 */
class PlacementDriveIntegrationTest extends TestCase
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

    private function makeInternship(array $overrides = []): Internship
    {
        return Internship::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Backend Intern',
            'status' => 'published',
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function drivePayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'title' => 'Campus Recruitment Drive 2026',
            'drive_type' => 'BOTH',
            'venue' => 'Main Auditorium',
            'scheduled_at' => now()->addDays(10)->toIso8601String(),
            'application_deadline' => now()->addDays(5)->toIso8601String(),
        ], $overrides);
    }

    private function createDrive(array $overrides = []): int
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->postJson('/api/admin/placement-drives', $this->drivePayload($overrides))
            ->assertCreated()
            ->json('data.id');
    }

    private function attach(int $driveId, string $type, int $opportunityId): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/placement-drives/{$driveId}/opportunities", [
            'opportunity_type' => $type,
            'opportunity_id' => $opportunityId,
        ])->assertCreated();
    }

    private function applyAsNewStudent(string $endpoint): int
    {
        Sanctum::actingAs($this->makeStudent(['email' => 'student-'.uniqid().'@careerconnect.edu']), ['*']);

        return $this->postJson($endpoint)->assertCreated()->json('data.id');
    }

    // --- aggregation --------------------------------------------------

    public function test_drive_aggregates_applications_across_attached_opportunities(): void
    {
        $jobPosting = $this->makeJobPosting();
        $internship = $this->makeInternship();
        $driveId = $this->createDrive();
        $this->attach($driveId, 'job', $jobPosting->id);
        $this->attach($driveId, 'internship', $internship->id);

        $this->applyAsNewStudent("/api/student/opportunities/jobs/{$jobPosting->id}/apply");
        $this->applyAsNewStudent("/api/student/opportunities/jobs/{$jobPosting->id}/apply");
        $this->applyAsNewStudent("/api/student/opportunities/internships/{$internship->id}/apply");

        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->getJson("/api/admin/placement-drives/{$driveId}");

        $response->assertOk();
        $response->assertJsonPath('data.application_count', 3);
    }

    public function test_drive_aggregates_selected_applications_across_attached_opportunities(): void
    {
        $jobPosting = $this->makeJobPosting();
        $internship = $this->makeInternship();
        $driveId = $this->createDrive();
        $this->attach($driveId, 'job', $jobPosting->id);
        $this->attach($driveId, 'internship', $internship->id);

        $jobAppId = $this->applyAsNewStudent("/api/student/opportunities/jobs/{$jobPosting->id}/apply");
        $this->applyAsNewStudent("/api/student/opportunities/jobs/{$jobPosting->id}/apply");
        $internshipAppId = $this->applyAsNewStudent("/api/student/opportunities/internships/{$internship->id}/apply");

        // Drive statistics derive purely from existing Application rows
        // via the real admin transition endpoint - no drive-specific
        // "selected" concept of its own.
        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/applications/{$jobAppId}/status", ['status' => ApplicationStatus::UNDER_REVIEW->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$jobAppId}/status", ['status' => ApplicationStatus::SHORTLISTED->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$jobAppId}/status", ['status' => ApplicationStatus::ASSESSMENT->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$jobAppId}/status", ['status' => ApplicationStatus::SELECTED->value])->assertOk();

        $this->patchJson("/api/admin/applications/{$internshipAppId}/status", ['status' => ApplicationStatus::UNDER_REVIEW->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$internshipAppId}/status", ['status' => ApplicationStatus::SHORTLISTED->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$internshipAppId}/status", ['status' => ApplicationStatus::ASSESSMENT->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$internshipAppId}/status", ['status' => ApplicationStatus::SELECTED->value])->assertOk();

        $response = $this->getJson("/api/admin/placement-drives/{$driveId}");

        $response->assertOk();
        $response->assertJsonPath('data.application_count', 3);
        $response->assertJsonPath('data.selected_count', 2);
    }

    public function test_drive_aggregates_interviews_through_attached_opportunities_applications(): void
    {
        $jobPosting = $this->makeJobPosting();
        $driveId = $this->createDrive();
        $this->attach($driveId, 'job', $jobPosting->id);

        $appId = $this->applyAsNewStudent("/api/student/opportunities/jobs/{$jobPosting->id}/apply");

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/applications/{$appId}/status", ['status' => ApplicationStatus::UNDER_REVIEW->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$appId}/status", ['status' => ApplicationStatus::SHORTLISTED->value])->assertOk();

        $this->postJson("/api/admin/applications/{$appId}/interviews", [
            'round' => 1,
            'title' => 'Technical Interview',
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
            'duration_minutes' => 60,
            'mode' => 'ONLINE',
            'meeting_url' => 'https://meet.example.com/room123',
            'interviewer_name' => 'Jane Doe',
            'interviewer_email' => 'jane.doe@novatech.example',
        ])->assertCreated();

        $response = $this->getJson("/api/admin/placement-drives/{$driveId}");

        $response->assertOk();
        $response->assertJsonPath('data.interview_count', 1);
    }

    // --- attaching leaves the opportunity's own publication status alone ---

    public function test_attaching_a_job_does_not_alter_its_publication_status(): void
    {
        $jobPosting = $this->makeJobPosting(['status' => 'published']);
        $driveId = $this->createDrive();

        $this->attach($driveId, 'job', $jobPosting->id);

        $this->assertDatabaseHas('job_postings', ['id' => $jobPosting->id, 'status' => 'published']);
    }

    public function test_attaching_an_internship_does_not_alter_its_publication_status(): void
    {
        $internship = $this->makeInternship(['status' => 'draft']);
        $driveId = $this->createDrive();

        $this->attach($driveId, 'internship', $internship->id);

        $this->assertDatabaseHas('internships', ['id' => $internship->id, 'status' => 'draft']);
    }

    // --- existing lifecycles remain unaffected -----------------------------

    public function test_application_lifecycle_still_works_through_a_drive_attached_opportunity(): void
    {
        $jobPosting = $this->makeJobPosting();
        $driveId = $this->createDrive();
        $this->attach($driveId, 'job', $jobPosting->id);

        $student = $this->makeStudent();
        Sanctum::actingAs($student, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('applications', ['id' => $applicationId, 'status' => ApplicationStatus::APPLIED->value]);

        $withdrawResponse = $this->postJson("/api/student/applications/{$applicationId}/withdraw", ['remarks' => 'Changed my mind.']);

        $withdrawResponse->assertOk();
        $this->assertDatabaseHas('applications', ['id' => $applicationId, 'status' => ApplicationStatus::WITHDRAWN->value]);
    }

    public function test_interview_lifecycle_still_works_on_an_application_tied_to_a_drive_attached_opportunity(): void
    {
        $jobPosting = $this->makeJobPosting();
        $driveId = $this->createDrive();
        $this->attach($driveId, 'job', $jobPosting->id);

        $student = $this->makeStudent();
        Sanctum::actingAs($student, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data.id');

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/applications/{$applicationId}/status", ['status' => ApplicationStatus::UNDER_REVIEW->value])->assertOk();
        $this->patchJson("/api/admin/applications/{$applicationId}/status", ['status' => ApplicationStatus::SHORTLISTED->value])->assertOk();

        $interview = $this->postJson("/api/admin/applications/{$applicationId}/interviews", [
            'round' => 1,
            'title' => 'Technical Interview',
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
            'duration_minutes' => 60,
            'mode' => 'ONLINE',
            'meeting_url' => 'https://meet.example.com/room123',
            'interviewer_name' => 'Jane Doe',
            'interviewer_email' => 'jane.doe@novatech.example',
        ])->assertCreated()->json('data');

        $rescheduled = $this->postJson("/api/admin/interviews/{$interview['id']}/reschedule", [
            'scheduled_at' => now()->addDays(5)->toIso8601String(),
        ]);
        $rescheduled->assertOk();
        $rescheduled->assertJsonPath('data.status', 'RESCHEDULED');

        $completed = $this->postJson("/api/admin/interviews/{$interview['id']}/complete", ['remarks' => 'Strong candidate.']);
        $completed->assertOk();
        $completed->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('interviews', ['id' => $interview['id'], 'status' => 'COMPLETED']);

        // A second interview on the same application, cancelled instead
        // of completed, to exercise the cancel branch of the same
        // unmodified InterviewService.
        $secondInterview = $this->postJson("/api/admin/applications/{$applicationId}/interviews", [
            'round' => 2,
            'title' => 'HR Round',
            'scheduled_at' => now()->addDays(7)->toIso8601String(),
            'duration_minutes' => 30,
            'mode' => 'ONLINE',
            'meeting_url' => 'https://meet.example.com/room456',
            'interviewer_name' => 'John Smith',
            'interviewer_email' => 'john.smith@novatech.example',
        ])->assertCreated()->json('data');

        $cancelled = $this->postJson("/api/admin/interviews/{$secondInterview['id']}/cancel", ['reason' => 'Position filled.']);
        $cancelled->assertOk();
        $cancelled->assertJsonPath('data.status', 'CANCELLED');
    }
}
