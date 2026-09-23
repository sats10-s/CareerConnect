<?php

namespace Tests\Feature\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\RoleName;
use App\Models\Application;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/admin/applications/{application}/interviews and
 * GET/PATCH/POST /api/admin/interviews[...] - see
 * Api\Admin\InterviewController and App\Services\InterviewService.
 */
class InterviewTest extends TestCase
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
    private function makeApplication(JobPosting $jobPosting): Application
    {
        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $data = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->json('data');

        return Application::findOrFail($data['id']);
    }

    /**
     * Fast-forwards a freshly-applied application straight to $status,
     * the same direct-update-after-real-apply convention already used
     * throughout Admin/ApplicationTest.php - a real interview module
     * only cares about the application already being in $status, not
     * how it legally got there.
     */
    private function makeApplicationInStatus(JobPosting $jobPosting, ApplicationStatus $status): Application
    {
        $application = $this->makeApplication($jobPosting);
        $application->update(['status' => $status]);

        return $application;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function interviewPayload(array $overrides = []): array
    {
        return array_merge([
            'round' => 1,
            'title' => 'Technical Interview',
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
            'duration_minutes' => 60,
            'mode' => 'ONLINE',
            'meeting_url' => 'https://meet.example.com/room123',
            'interviewer_name' => 'Jane Doe',
            'interviewer_email' => 'jane.doe@novatech.example',
        ], $overrides);
    }

    public function test_admin_can_create_interview_for_shortlisted_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.round', 1);
        $response->assertJsonPath('data.status', 'SCHEDULED');
        $this->assertDatabaseHas('interviews', ['application_id' => $application->id, 'status' => 'SCHEDULED']);
    }

    public function test_admin_can_create_interview_for_assessment_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::ASSESSMENT);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload());

        $response->assertCreated();
        $this->assertDatabaseHas('interviews', ['application_id' => $application->id, 'status' => 'SCHEDULED']);
    }

    public function test_admin_can_create_interview_for_application_already_in_interview_status(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::INTERVIEW);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload(['round' => 2]));

        $response->assertCreated();
        $this->assertDatabaseHas('interviews', ['application_id' => $application->id, 'round' => 2]);
    }

    public function test_interview_creation_creates_history(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $this->assertDatabaseHas('interview_status_history', [
            'interview_id' => $interviewId,
            'from_status' => null,
            'to_status' => 'SCHEDULED',
        ]);
        $this->assertSame(1, Interview::findOrFail($interviewId)->statusHistory()->count());
    }

    public function test_creation_moves_shortlisted_application_to_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())->assertCreated();

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'INTERVIEW']);
        $this->assertDatabaseHas('application_status_history', [
            'application_id' => $application->id,
            'from_status' => 'SHORTLISTED',
            'to_status' => 'INTERVIEW',
        ]);
    }

    public function test_creation_moves_assessment_application_to_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::ASSESSMENT);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())->assertCreated();

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'INTERVIEW']);
        $this->assertDatabaseHas('application_status_history', [
            'application_id' => $application->id,
            'from_status' => 'ASSESSMENT',
            'to_status' => 'INTERVIEW',
        ]);
    }

    public function test_creating_interview_for_interview_status_application_does_not_duplicate_transition(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::INTERVIEW);
        $historyCountBefore = $application->statusHistory()->count();

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())->assertCreated();

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'INTERVIEW']);
        $this->assertSame($historyCountBefore, $application->statusHistory()->count());
    }

    public function test_cannot_create_interview_for_applied_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplication($jobPosting);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('interviews', ['application_id' => $application->id]);
    }

    public function test_cannot_create_interview_for_under_review_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::UNDER_REVIEW);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('interviews', ['application_id' => $application->id]);
    }

    public function test_cannot_create_interview_for_rejected_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::REJECTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('interviews', ['application_id' => $application->id]);
    }

    public function test_cannot_create_interview_for_withdrawn_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::WITHDRAWN);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('interviews', ['application_id' => $application->id]);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload(['mode' => 'REMOTE']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
    }

    public function test_online_mode_requires_meeting_url(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $payload = $this->interviewPayload(['mode' => 'ONLINE']);
        unset($payload['meeting_url']);

        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['meeting_url']);
    }

    public function test_offline_mode_requires_venue(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $payload = $this->interviewPayload(['mode' => 'OFFLINE']);
        unset($payload['meeting_url']);

        $response = $this->postJson("/api/admin/applications/{$application->id}/interviews", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['venue']);
    }

    public function test_invalid_scheduled_data_is_rejected(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload(['scheduled_at' => 'not-a-date']))
            ->assertJsonValidationErrors(['scheduled_at']);

        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload(['duration_minutes' => -5]))
            ->assertJsonValidationErrors(['duration_minutes']);
    }

    public function test_admin_can_list_interviews(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())->assertCreated();

        $response = $this->getJson('/api/admin/interviews');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['round' => 1]);
    }

    public function test_admin_can_view_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $response = $this->getJson("/api/admin/interviews/{$interviewId}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $interviewId);
        $response->assertJsonCount(1, 'data.status_history');
        $response->assertJsonStructure(['data' => ['student' => ['name', 'roll_number', 'department']]]);
    }

    public function test_admin_can_reschedule_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $response = $this->postJson("/api/admin/interviews/{$interviewId}/reschedule", [
            'scheduled_at' => now()->addDays(7)->toIso8601String(),
            'remarks' => 'Interviewer unavailable.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'RESCHEDULED');
        $this->assertDatabaseHas('interviews', ['id' => $interviewId, 'status' => 'RESCHEDULED']);
    }

    public function test_reschedule_preserves_history(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $this->postJson("/api/admin/interviews/{$interviewId}/reschedule", [
            'scheduled_at' => now()->addDays(7)->toIso8601String(),
        ])->assertOk();

        $interview = Interview::findOrFail($interviewId);
        $this->assertSame(2, $interview->statusHistory()->count());
        $this->assertDatabaseHas('interview_status_history', [
            'interview_id' => $interviewId,
            'from_status' => 'SCHEDULED',
            'to_status' => 'RESCHEDULED',
        ]);
    }

    public function test_admin_can_complete_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $response = $this->postJson("/api/admin/interviews/{$interviewId}/complete", ['remarks' => 'Strong candidate.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'COMPLETED');
        $this->assertNotNull($response->json('data.completed_at'));
        // Selection remains an application/admin decision - completing
        // an interview must NOT auto-change the application status.
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'INTERVIEW']);
    }

    public function test_admin_can_cancel_interview(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $response = $this->postJson("/api/admin/interviews/{$interviewId}/cancel", ['reason' => 'Candidate withdrew.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CANCELLED');
        $this->assertNotNull($response->json('data.cancelled_at'));
        // Cancelling an interview must NOT auto-reject the application.
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'INTERVIEW']);
    }

    public function test_cancel_requires_reason(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $response = $this->postJson("/api/admin/interviews/{$interviewId}/cancel", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_completed_interview_cannot_be_completed_again(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');
        $this->postJson("/api/admin/interviews/{$interviewId}/complete")->assertOk();

        $this->postJson("/api/admin/interviews/{$interviewId}/complete")->assertStatus(422);
    }

    public function test_cancelled_interview_cannot_be_cancelled_again(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');
        $this->postJson("/api/admin/interviews/{$interviewId}/cancel", ['reason' => 'First cancellation.'])->assertOk();

        $this->postJson("/api/admin/interviews/{$interviewId}/cancel", ['reason' => 'Second attempt.'])->assertStatus(422);
    }

    public function test_completed_interview_cannot_be_rescheduled(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');
        $this->postJson("/api/admin/interviews/{$interviewId}/complete")->assertOk();

        $response = $this->postJson("/api/admin/interviews/{$interviewId}/reschedule", [
            'scheduled_at' => now()->addDays(1)->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }

    public function test_student_cannot_manage_interviews(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->assertStatus(403);
        $this->getJson('/api/admin/interviews')->assertStatus(403);
    }

    public function test_faculty_cannot_manage_interviews(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeFaculty(), ['*']);
        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->assertStatus(403);
        $this->getJson('/api/admin/interviews')->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_admin_interviews(): void
    {
        $this->getJson('/api/admin/interviews')->assertStatus(401);
    }

    public function test_interview_belongs_to_its_application(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');

        $interview = Interview::findOrFail($interviewId);
        $this->assertSame($application->id, $interview->application_id);
        $this->assertTrue($application->interviews()->where('id', $interview->id)->exists());
    }

    public function test_status_history_is_append_only(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplicationInStatus($jobPosting, ApplicationStatus::SHORTLISTED);

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->json('data.id');
        $this->postJson("/api/admin/interviews/{$interviewId}/reschedule", [
            'scheduled_at' => now()->addDays(5)->toIso8601String(),
        ])->assertOk();
        $this->postJson("/api/admin/interviews/{$interviewId}/complete")->assertOk();

        $interview = Interview::findOrFail($interviewId);
        $this->assertSame(3, $interview->statusHistory()->count());
        $this->assertDatabaseHas('interview_status_history', ['interview_id' => $interviewId, 'from_status' => null, 'to_status' => 'SCHEDULED']);
        $this->assertDatabaseHas('interview_status_history', ['interview_id' => $interviewId, 'from_status' => 'SCHEDULED', 'to_status' => 'RESCHEDULED']);
        $this->assertDatabaseHas('interview_status_history', ['interview_id' => $interviewId, 'from_status' => 'RESCHEDULED', 'to_status' => 'COMPLETED']);
    }

    public function test_rejected_creation_leaves_no_partial_state(): void
    {
        $jobPosting = $this->makeJobPosting();
        $application = $this->makeApplication($jobPosting);
        $historyCountBefore = $application->statusHistory()->count();

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->postJson("/api/admin/applications/{$application->id}/interviews", $this->interviewPayload())
            ->assertStatus(422);

        $this->assertDatabaseMissing('interviews', ['application_id' => $application->id]);
        $this->assertDatabaseCount('interview_status_history', 0);
        $this->assertSame($historyCountBefore, $application->statusHistory()->count());
    }
}
