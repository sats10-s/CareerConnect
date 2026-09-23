<?php

namespace Tests\Feature\Student;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\WeeklyProgressReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/student/weekly-reports[...] and
 * /api/student/internship-assignments/{assignment}/weekly-reports - see
 * Api\Student\WeeklyProgressReportController and
 * App\Services\WeeklyProgressReportService. Also covers the create/
 * update/submit business rules (week validity, duplicate week, content
 * completeness) since they are all exercised through these endpoints.
 */
class WeeklyProgressReportTest extends TestCase
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
     * An ACTIVE internship assignment whose started_at has been pushed
     * back well into the past (90 days, ~12 weeks), so tests can create
     * reports for several distinct past week numbers without ever
     * hitting the "week has not started yet" rule. This bypasses the
     * API purely for test setup (mirrors AttendanceTest's own
     * subDays(10) precedent) - it does not exercise the activate
     * endpoint's own behaviour.
     *
     * @return array{admin: User, faculty: Faculty, student: User, internship: Internship, application_id: int, assignment_id: int, started_at: Carbon}
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

        $startedAt = now()->subDays(90)->startOfDay();
        InternshipAssignment::whereKey($assignmentId)->update(['started_at' => $startedAt]);

        return [
            'admin' => $admin,
            'faculty' => $faculty,
            'student' => $studentUser,
            'internship' => $internship,
            'application_id' => $applicationId,
            'assignment_id' => $assignmentId,
            'started_at' => $startedAt,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function weekDates(Carbon $anchor, int $weekNumber): array
    {
        $start = $anchor->copy()->addDays(7 * ($weekNumber - 1));
        $end = $start->copy()->addDays(6);

        return [$start->toDateString(), $end->toDateString()];
    }

    private function createDraft(User $studentUser, array $fixture, int $weekNumber, array $overrides = []): array
    {
        [$start, $end] = $this->weekDates($fixture['started_at'], $weekNumber);

        Sanctum::actingAs($studentUser, ['*']);

        return $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", array_merge([
            'week_number' => $weekNumber,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => "Worked on backend APIs during week {$weekNumber}.",
            'learning_outcomes' => 'Learned about Laravel service layers.',
            'challenges' => 'Debugging migrations.',
            'next_week_plan' => 'Continue with integration tests.',
        ], $overrides))->assertCreated()->json('data');
    }

    private function draftFixture(): array
    {
        $fixture = $this->activatedFixture();
        $report = $this->createDraft($fixture['student'], $fixture, 1);
        $fixture['report_id'] = $report['id'];

        return $fixture;
    }

    private function submittedFixture(): array
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertOk();

        return $fixture;
    }

    private function underReviewFixture(): array
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'UNDER_REVIEW'])->assertOk();

        return $fixture;
    }

    private function approvedFixture(): array
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'APPROVED'])->assertOk();

        return $fixture;
    }

    private function changesRequestedFixture(): array
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", [
            'action' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Please add more detail on the challenges faced.',
        ])->assertOk();

        return $fixture;
    }

    // --- create / update ------------------------------------------------------

    public function test_student_can_create_draft_report(): void
    {
        $fixture = $this->activatedFixture();

        $report = $this->createDraft($fixture['student'], $fixture, 1);

        $this->assertSame('DRAFT', $report['status']);
        $this->assertDatabaseHas('weekly_progress_reports', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'week_number' => 1,
            'status' => 'DRAFT',
        ]);
    }

    public function test_creating_draft_creates_initial_history_row(): void
    {
        $fixture = $this->draftFixture();

        $this->assertDatabaseHas('weekly_progress_report_status_history', [
            'weekly_progress_report_id' => $fixture['report_id'],
            'from_status' => null,
            'to_status' => 'DRAFT',
        ]);
        $this->assertSame(1, WeeklyProgressReport::findOrFail($fixture['report_id'])->statusHistory()->count());
    }

    public function test_student_can_update_own_draft(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", [
            'work_done' => 'Updated work summary for week 1.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.work_done', 'Updated work summary for week 1.');
        $this->assertDatabaseHas('weekly_progress_reports', [
            'id' => $fixture['report_id'],
            'work_done' => 'Updated work summary for week 1.',
        ]);
    }

    public function test_updating_draft_does_not_create_a_history_row(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Revised.'])->assertOk();

        $this->assertSame(1, WeeklyProgressReport::findOrFail($fixture['report_id'])->statusHistory()->count());
    }

    public function test_update_cannot_change_assignment_or_week_number(): void
    {
        $fixture = $this->draftFixture();
        $other = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", [
            'internship_assignment_id' => $other['assignment_id'],
            'week_number' => 5,
            'work_done' => 'Still week 1 content.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('weekly_progress_reports', [
            'id' => $fixture['report_id'],
            'internship_assignment_id' => $fixture['assignment_id'],
            'week_number' => 1,
        ]);
    }

    public function test_student_cannot_set_status_manually_on_create(): void
    {
        $fixture = $this->activatedFixture();
        [$start, $end] = $this->weekDates($fixture['started_at'], 1);

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Trying to sneak in an approved status.',
            'status' => 'APPROVED',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'DRAFT');
    }

    // --- submit -----------------------------------------------------------

    public function test_student_can_submit_draft(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUBMITTED');
        $this->assertNotNull($response->json('data.student_submitted_at'));
        $this->assertDatabaseHas('weekly_progress_report_status_history', [
            'weekly_progress_report_id' => $fixture['report_id'],
            'from_status' => 'DRAFT',
            'to_status' => 'SUBMITTED',
        ]);
    }

    public function test_student_can_resubmit_after_changes_requested(): void
    {
        $fixture = $this->changesRequestedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Addressed the requested changes.'])->assertOk();
        $response = $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUBMITTED');
        $this->assertDatabaseHas('weekly_progress_report_status_history', [
            'weekly_progress_report_id' => $fixture['report_id'],
            'from_status' => 'CHANGES_REQUESTED',
            'to_status' => 'SUBMITTED',
        ]);
    }

    public function test_student_cannot_directly_set_report_to_under_review_or_approved(): void
    {
        // There is no student-facing action that accepts an arbitrary
        // target status at all - submit() only ever moves DRAFT/
        // CHANGES_REQUESTED -> SUBMITTED, so the review-only statuses
        // are unreachable through this controller by construction. The
        // faculty-only review route is the one place they can be set,
        // and a student is forbidden from calling it (see
        // test_student_cannot_review_own_report below).
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertOk();
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $fixture['report_id'], 'status' => 'SUBMITTED']);
    }

    public function test_submitting_already_submitted_report_is_rejected(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit");

        $response->assertStatus(422);
    }

    public function test_empty_work_done_is_rejected_on_submit(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => ''])->assertOk();
        $response = $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit");

        $response->assertStatus(422);
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $fixture['report_id'], 'status' => 'DRAFT']);
    }

    // --- view / list --------------------------------------------------------

    public function test_student_can_view_own_report(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/weekly-reports/{$fixture['report_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['report_id']);
        // Student-facing detail never exposes the faculty/student/
        // internship summary block or history - see
        // WeeklyProgressReportResource's docblock.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('history', $data);
        $this->assertArrayNotHasKey('faculty', $data);
    }

    public function test_student_can_list_own_reports(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/weekly-reports');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_student_can_view_reports_for_assignment(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.records'));
    }

    public function test_student_can_view_previous_approved_reports(): void
    {
        $fixture = $this->approvedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/weekly-reports/{$fixture['report_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');
    }

    // --- authorization / IDOR ------------------------------------------------

    public function test_student_cannot_edit_approved_report(): void
    {
        $fixture = $this->approvedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Trying to change after approval.']);

        $response->assertStatus(422);
    }

    public function test_student_cannot_submit_approved_report(): void
    {
        $fixture = $this->approvedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertStatus(422);
    }

    public function test_student_cannot_access_another_students_report(): void
    {
        $fixture = $this->draftFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student@careerconnect.edu']);

        Sanctum::actingAs($intruder, ['*']);
        $this->getJson("/api/student/weekly-reports/{$fixture['report_id']}")->assertStatus(403);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Hijacked.'])->assertStatus(403);
        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertStatus(403);
    }

    public function test_student_cannot_access_another_assignment(): void
    {
        $fixture = $this->draftFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student2@careerconnect.edu']);
        // A fully valid payload (relative to the fixture's OWN
        // assignment/period) is required here - Laravel resolves and
        // validates the injected StoreWeeklyProgressReportRequest
        // before the controller body (and its Gate::authorize call)
        // ever runs, so an incomplete payload would fail with 422
        // rather than exercising the authorization check this test is
        // actually for.
        [$start, $end] = $this->weekDates($fixture['started_at'], 2);

        Sanctum::actingAs($intruder, ['*']);
        $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports")->assertStatus(403);
        $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 2,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Not my assignment.',
        ])->assertStatus(403);
    }

    public function test_student_list_never_includes_another_students_reports(): void
    {
        $fixture = $this->draftFixture();
        $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/weekly-reports');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['report_id']);
    }

    public function test_student_cannot_review_own_report(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'UNDER_REVIEW'])->assertStatus(403);
    }

    public function test_student_cannot_access_admin_weekly_report_routes(): void
    {
        $fixture = $this->draftFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson('/api/admin/weekly-reports')->assertStatus(403);
        $this->getJson("/api/admin/weekly-reports/{$fixture['report_id']}")->assertStatus(403);
    }

    // --- week validity -------------------------------------------------------

    public function test_duplicate_week_is_rejected(): void
    {
        $fixture = $this->draftFixture();
        [$start, $end] = $this->weekDates($fixture['started_at'], 1);

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Duplicate week attempt.',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, WeeklyProgressReport::where('internship_assignment_id', $fixture['assignment_id'])->where('week_number', 1)->count());
    }

    public function test_future_week_is_rejected(): void
    {
        $fixture = $this->activatedFixture();
        $start = now()->addDays(7)->toDateString();
        $end = now()->addDays(13)->toDateString();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 99,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Future week attempt.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['week_start_date']);
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $fixture = $this->activatedFixture();
        [$start] = $this->weekDates($fixture['started_at'], 1);
        // Only 3 days, not a full 7-day week.
        $end = Carbon::parse($start)->addDays(3)->toDateString();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Bad date range.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['week_end_date']);
    }

    public function test_week_end_before_week_start_is_rejected(): void
    {
        $fixture = $this->activatedFixture();
        [$start] = $this->weekDates($fixture['started_at'], 1);
        $end = Carbon::parse($start)->subDay()->toDateString();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Inverted range.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['week_end_date']);
    }

    public function test_week_entirely_before_internship_start_is_rejected(): void
    {
        $fixture = $this->activatedFixture();
        $start = $fixture['started_at']->copy()->subDays(14)->toDateString();
        $end = Carbon::parse($start)->addDays(6)->toDateString();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Before the internship even started.',
        ]);

        $response->assertStatus(422);
    }

    public function test_empty_work_done_is_rejected_on_create(): void
    {
        $fixture = $this->activatedFixture();
        [$start, $end] = $this->weekDates($fixture['started_at'], 1);

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['work_done']);
    }

    // --- assignment status gating --------------------------------------------

    public function test_completed_assignment_blocks_new_reports(): void
    {
        $fixture = $this->activatedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        [$start, $end] = $this->weekDates($fixture['started_at'], 1);
        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'After completion.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('weekly_progress_reports', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_cancelled_assignment_blocks_new_reports(): void
    {
        $fixture = $this->activatedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Withdrawn.'])->assertOk();

        [$start, $end] = $this->weekDates($fixture['started_at'], 1);
        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'After cancellation.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('weekly_progress_reports', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_completed_assignment_blocks_editing_existing_reports(): void
    {
        $fixture = $this->draftFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Editing after completion.']);

        $response->assertStatus(422);
    }

    // --- security -------------------------------------------------------------

    public function test_guest_cannot_access_student_weekly_report_routes(): void
    {
        $fixture = $this->draftFixture();

        // draftFixture() ends with the auth guard authenticated as the
        // student user (it drives real admin/faculty endpoints to build
        // the fixture) - the guard must be explicitly reset for the one
        // test here that wants a genuinely logged-out request.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/student/weekly-reports')->assertStatus(401);
        $this->getJson("/api/student/weekly-reports/{$fixture['report_id']}")->assertStatus(401);
        $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports")->assertStatus(401);
        $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [])->assertStatus(401);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", [])->assertStatus(401);
        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertStatus(401);
    }
}
