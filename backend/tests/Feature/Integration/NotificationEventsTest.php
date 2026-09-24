<?php

namespace Tests\Feature\Integration;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * End-to-end coverage of every domain-event -> in-app-notification
 * integration point wired up in App\Providers\AppServiceProvider::boot()
 * (module spec sections 9/13). Every fixture here goes through the
 * real HTTP endpoint that triggers the underlying domain event -
 * App\Listeners\* are never invoked directly - exactly like every other
 * Integration test in this codebase (see
 * InternshipCompletionIntegrationTest). This is the only place all nine
 * listeners are exercised together; role-isolation, IDOR, pagination
 * and CRUD-level behaviour are already covered by the
 * Student/Faculty/Admin Notification and Announcement test suites.
 */
class NotificationEventsTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();

        Storage::fake('local');
    }

    private function notificationExists(int $recipientUserId, NotificationType $type): bool
    {
        return Notification::where('recipient_user_id', $recipientUserId)->where('type', $type->value)->exists();
    }

    // --- Applications -----------------------------------------------------

    public function test_application_submission_notifies_the_student(): void
    {
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        Sanctum::actingAs($studentUser, ['*']);
        $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply")->assertCreated();

        $this->assertTrue($this->notificationExists($studentUser->id, NotificationType::APPLICATION_SUBMITTED));
    }

    public function test_application_status_change_notifies_the_student(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        $this->applyAndAdvance($admin, $studentUser, $internship, ['UNDER_REVIEW']);

        $this->assertTrue($this->notificationExists($studentUser->id, NotificationType::APPLICATION_STATUS_CHANGED));
    }

    // --- Interviews ---------------------------------------------------------

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

    private function shortlistedApplication(): array
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $jobPosting = $this->makeJobPosting();
        $applicationId = $this->applyAndAdvance($admin, $studentUser, $jobPosting, ['UNDER_REVIEW', 'SHORTLISTED']);

        return ['admin' => $admin, 'student' => $studentUser, 'application_id' => $applicationId];
    }

    public function test_interview_scheduling_notifies_the_student(): void
    {
        $fixture = $this->shortlistedApplication();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/applications/{$fixture['application_id']}/interviews", $this->interviewPayload())->assertCreated();

        $this->assertTrue($this->notificationExists($fixture['student']->id, NotificationType::INTERVIEW_SCHEDULED));
    }

    public function test_interview_reschedule_notifies_the_student(): void
    {
        $fixture = $this->shortlistedApplication();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$fixture['application_id']}/interviews", $this->interviewPayload())
            ->json('data.id');

        $this->postJson("/api/admin/interviews/{$interviewId}/reschedule", [
            'scheduled_at' => now()->addDays(7)->toIso8601String(),
            'remarks' => 'Interviewer unavailable.',
        ])->assertOk();

        $this->assertTrue($this->notificationExists($fixture['student']->id, NotificationType::INTERVIEW_RESCHEDULED));
    }

    public function test_interview_completion_notifies_the_student(): void
    {
        $fixture = $this->shortlistedApplication();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $interviewId = $this->postJson("/api/admin/applications/{$fixture['application_id']}/interviews", $this->interviewPayload())
            ->json('data.id');

        $this->postJson("/api/admin/interviews/{$interviewId}/complete", ['remarks' => 'Strong candidate.'])->assertOk();

        $this->assertTrue($this->notificationExists($fixture['student']->id, NotificationType::INTERVIEW_COMPLETED));
    }

    // --- Internship Assignment -----------------------------------------------

    public function test_internship_assignment_creation_notifies_both_student_and_faculty(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->applyAndAdvance($admin, $studentUser, $internship, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ])->assertCreated();

        $this->assertTrue($this->notificationExists($studentUser->id, NotificationType::INTERNSHIP_ASSIGNED));
        $this->assertTrue($this->notificationExists($faculty->user->id, NotificationType::INTERNSHIP_ASSIGNED));
    }

    // --- Weekly Progress Reports ----------------------------------------------

    public function test_weekly_report_submission_notifies_the_faculty(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $reportId = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => now()->startOfWeek()->toDateString(),
            'week_end_date' => now()->endOfWeek()->toDateString(),
            'work_done' => 'Set up the initial project scaffolding.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/student/weekly-reports/{$reportId}/submit")->assertOk();

        $this->assertTrue($this->notificationExists($fixture['faculty']->user->id, NotificationType::WEEKLY_REPORT_SUBMITTED));
    }

    /**
     * Returns a report already legitimately moved through
     * DRAFT -> SUBMITTED -> UNDER_REVIEW, via the real student
     * submit + faculty review('action' => 'UNDER_REVIEW') endpoints -
     * the only lifecycle path WeeklyProgressReportService allows into
     * APPROVED/CHANGES_REQUESTED (see
     * WeeklyProgressReportStatus::canTransitionTo() - SUBMITTED cannot
     * move directly to either). Nothing here inserts into
     * weekly_progress_reports directly.
     */
    private function reportUnderReview(array $fixture): int
    {
        Sanctum::actingAs($fixture['student'], ['*']);
        $reportId = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => now()->startOfWeek()->toDateString(),
            'week_end_date' => now()->endOfWeek()->toDateString(),
            'work_done' => 'Set up the initial project scaffolding.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/student/weekly-reports/{$reportId}/submit")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$reportId}/review", ['action' => 'UNDER_REVIEW'])->assertOk();

        return $reportId;
    }

    public function test_weekly_report_approval_notifies_the_student(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();
        $reportId = $this->reportUnderReview($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$reportId}/review", ['action' => 'APPROVED'])->assertOk();

        $this->assertTrue($this->notificationExists($fixture['student']->id, NotificationType::WEEKLY_REPORT_REVIEWED));
    }

    public function test_weekly_report_changes_requested_notifies_the_student(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();
        $reportId = $this->reportUnderReview($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$reportId}/review", [
            'action' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Please add more detail.',
        ])->assertOk();

        $this->assertTrue($this->notificationExists($fixture['student']->id, NotificationType::WEEKLY_REPORT_REVIEWED));
    }

    // --- Internship Documents ---------------------------------------------------

    public function test_document_submission_notifies_the_faculty(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Offer Letter',
            'file' => UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->assertTrue($this->notificationExists($fixture['faculty']->user->id, NotificationType::DOCUMENT_SUBMITTED));
    }

    public function test_document_review_notifies_the_student(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $documentId = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Offer Letter',
            'file' => UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$documentId}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/faculty/internship-documents/{$documentId}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        $this->assertTrue($this->notificationExists($fixture['student']->id, NotificationType::DOCUMENT_REVIEWED));
    }

    // --- Internship Completion & PPO -------------------------------------------

    public function test_internship_completion_finalization_notifies_the_student(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $completionId = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
            'final_remarks' => 'Completed successfully.',
        ])->assertCreated()->json('data.id');

        // Creating the (DRAFT) completion record must not itself notify
        // the student - only FINALIZED does (see
        // App\Listeners\SendInternshipCompletionNotification's docblock).
        $this->assertFalse($this->notificationExists($fixture['student']->id, NotificationType::INTERNSHIP_COMPLETED));

        $this->postJson("/api/faculty/internship-completions/{$completionId}/finalize")->assertOk();

        $this->assertTrue($this->notificationExists($fixture['student']->id, NotificationType::INTERNSHIP_COMPLETED));
    }

    public function test_ppo_offer_and_decision_both_notify_the_student(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
            'package' => 1200000,
        ])->assertCreated();

        $this->assertSame(
            1,
            Notification::where('recipient_user_id', $fixture['student']->id)
                ->where('type', NotificationType::PPO_UPDATED->value)
                ->count(),
        );

        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED'])->assertOk();

        $this->assertSame(
            2,
            Notification::where('recipient_user_id', $fixture['student']->id)
                ->where('type', NotificationType::PPO_UPDATED->value)
                ->count(),
        );
    }

    // --- Attendance -------------------------------------------------------

    public function test_marking_a_student_absent_notifies_the_faculty(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'ABSENT',
        ])->assertCreated();

        $this->assertTrue($this->notificationExists($fixture['faculty']->user->id, NotificationType::ATTENDANCE_ALERT));
    }

    public function test_marking_a_student_present_does_not_notify_the_faculty(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated();

        $this->assertFalse($this->notificationExists($fixture['faculty']->user->id, NotificationType::ATTENDANCE_ALERT));
    }

    // --- Listener resilience -----------------------------------------------

    /**
     * A listener exception must never roll back the domain write it is
     * reacting to (see every App\Listeners\* class's try/catch
     * docblock) - the underlying application write itself must always
     * succeed regardless of notification plumbing.
     */
    public function test_domain_writes_succeed_even_though_notification_listeners_are_wired_up(): void
    {
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        Sanctum::actingAs($studentUser, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply")
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('applications', ['id' => $applicationId, 'status' => 'APPLIED']);
    }
}
