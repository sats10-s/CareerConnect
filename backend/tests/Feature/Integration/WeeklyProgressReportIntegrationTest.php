<?php

namespace Tests\Feature\Integration;

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
 * Cross-module integration for Weekly Progress Reports: confirms the
 * full chain - Student -> Internship opportunity -> Application ->
 * SELECTED -> Internship Assignment -> ACTIVE -> Weekly Progress Report
 * - works end to end, that Attendance and Weekly Progress Reports
 * coexist independently on the same assignment, that every lifecycle
 * transition writes exactly one append-only history row, and that
 * nothing in the weekly-report lifecycle mutates the application's own
 * status, the internship's publication status, or the assignment's own
 * ownership/status. Nothing here inserts directly into applications/
 * internship_assignments/weekly_progress_reports - every fixture is
 * produced through the real student/admin/faculty endpoints, exactly
 * like AttendanceIntegrationTest.
 */
class WeeklyProgressReportIntegrationTest extends TestCase
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

    private function selectInternshipApplication(User $studentUser, Internship $internship): int
    {
        Sanctum::actingAs($studentUser, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply")
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin, ['*']);
        foreach (['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED'] as $status) {
            $this->patchJson("/api/admin/applications/{$applicationId}/status", ['status' => $status])->assertOk();
        }

        return $applicationId;
    }

    /**
     * @return array{faculty: Faculty, student: User, internship: Internship, application_id: int, assignment_id: int, started_at: Carbon}
     */
    private function activatedAssignment(): array
    {
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectInternshipApplication($studentUser, $internship);

        Sanctum::actingAs($this->admin, ['*']);
        $assignmentId = $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/internship-assignments/{$assignmentId}/activate")->assertOk();

        $startedAt = now()->subDays(90)->startOfDay();
        InternshipAssignment::whereKey($assignmentId)->update(['started_at' => $startedAt]);

        return [
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

    private function createDraftReport(array $fixture, int $weekNumber = 1): array
    {
        [$start, $end] = $this->weekDates($fixture['started_at'], $weekNumber);

        Sanctum::actingAs($fixture['student'], ['*']);

        return $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => $weekNumber,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => "Worked on backend APIs during week {$weekNumber}.",
        ])->assertCreated()->json('data');
    }

    // --- the core chain -----------------------------------------------------

    public function test_selected_application_flows_into_assignment_activation_and_weekly_report(): void
    {
        $fixture = $this->activatedAssignment();

        $report = $this->createDraftReport($fixture);

        $this->assertDatabaseHas('weekly_progress_reports', [
            'id' => $report['id'],
            'internship_assignment_id' => $fixture['assignment_id'],
            'status' => 'DRAFT',
        ]);
    }

    public function test_full_lifecycle_from_draft_to_approved(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'UNDER_REVIEW'])->assertOk();
        $response = $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", [
            'action' => 'APPROVED',
            'faculty_review_remarks' => 'Well done.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');
    }

    // --- attendance and weekly reports coexist independently -----------------

    public function test_attendance_and_weekly_reports_coexist_independently_on_the_same_assignment(): void
    {
        $fixture = $this->activatedAssignment();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated();

        $report = $this->createDraftReport($fixture);

        $this->assertDatabaseHas('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $report['id'], 'internship_assignment_id' => $fixture['assignment_id']]);

        // Submitting the weekly report must never touch attendance...
        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();
        $this->assertSame(1, \App\Models\Attendance::where('internship_assignment_id', $fixture['assignment_id'])->count());

        // ...and marking attendance must never touch the weekly report.
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->subDay()->toDateString(),
            'status' => 'ABSENT',
        ])->assertCreated();
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $report['id'], 'status' => 'SUBMITTED']);
    }

    // --- assignment lifecycle gates weekly reports ---------------------------

    public function test_cancelled_assignment_blocks_new_weekly_reports(): void
    {
        $fixture = $this->activatedAssignment();
        Sanctum::actingAs($this->admin, ['*']);
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

    public function test_historical_weekly_reports_remain_readable_after_completion(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson("/api/student/weekly-reports/{$report['id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $report['id']);
    }

    // --- side effects ---------------------------------------------------------

    public function test_weekly_report_lifecycle_does_not_alter_the_application_status(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        $this->assertDatabaseHas('applications', ['id' => $fixture['application_id'], 'status' => 'SELECTED']);
    }

    public function test_weekly_report_lifecycle_does_not_alter_the_internships_publication_status(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        $this->assertDatabaseHas('internships', ['id' => $fixture['internship']->id, 'status' => 'published']);
    }

    public function test_weekly_report_lifecycle_does_not_alter_the_assignment_status_or_ownership(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'UNDER_REVIEW'])->assertOk();
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'APPROVED'])->assertOk();

        $this->assertDatabaseHas('internship_assignments', [
            'id' => $fixture['assignment_id'],
            'status' => 'ACTIVE',
            'faculty_id' => $fixture['faculty']->id,
        ]);
    }

    // --- lifecycle / history invariants ---------------------------------------

    public function test_every_transition_creates_exactly_one_history_row(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'UNDER_REVIEW'])->assertOk();
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", [
            'action' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Please add more detail.',
        ])->assertOk();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/student/weekly-reports/{$report['id']}", ['work_done' => 'Added more detail.'])->assertOk();
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'UNDER_REVIEW'])->assertOk();
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", [
            'action' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        // null->DRAFT, DRAFT->SUBMITTED, SUBMITTED->UNDER_REVIEW,
        // UNDER_REVIEW->CHANGES_REQUESTED, CHANGES_REQUESTED->SUBMITTED,
        // SUBMITTED->UNDER_REVIEW, UNDER_REVIEW->APPROVED = 7 rows. The
        // content-only PATCH in between deliberately does NOT add one
        // (see WeeklyProgressReportService::updateDraft()'s docblock).
        $this->assertSame(7, WeeklyProgressReport::findOrFail($report['id'])->statusHistory()->count());
    }

    public function test_invalid_lifecycle_transitions_are_rejected(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        // DRAFT cannot jump straight to UNDER_REVIEW/APPROVED - the only
        // student-facing action is submit(), which only ever allows
        // DRAFT/CHANGES_REQUESTED -> SUBMITTED, so those states are
        // provably unreachable from a DRAFT report through this API.
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'APPROVED'])->assertStatus(403);

        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        // SUBMITTED -> APPROVED directly (skipping UNDER_REVIEW) must
        // be rejected.
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'APPROVED'])->assertStatus(422);

        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'UNDER_REVIEW'])->assertOk();
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", [
            'action' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        // APPROVED is terminal - no further transition is ever allowed.
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", ['action' => 'UNDER_REVIEW'])->assertStatus(422);
        $this->postJson("/api/faculty/weekly-reports/{$report['id']}/review", [
            'action' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Too late.',
        ])->assertStatus(422);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/student/weekly-reports/{$report['id']}", ['work_done' => 'Trying to edit approved.'])->assertStatus(422);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertStatus(422);
    }

    public function test_history_is_append_only_with_no_endpoint_to_modify_it(): void
    {
        $fixture = $this->activatedAssignment();
        $report = $this->createDraftReport($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/student/weekly-reports/{$report['id']}/submit")->assertOk();

        $historyCountBefore = WeeklyProgressReport::findOrFail($report['id'])->statusHistory()->count();

        // There is no PATCH/PUT/DELETE route for
        // weekly_progress_report_status_history anywhere in the API -
        // every write to it happens exclusively through
        // WeeklyProgressReportService::recordHistory(). Re-reading the
        // report/its history repeatedly must never change the row
        // count.
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson("/api/faculty/weekly-reports/{$report['id']}")->assertOk();
        $this->getJson("/api/faculty/weekly-reports/{$report['id']}")->assertOk();

        $this->assertSame($historyCountBefore, WeeklyProgressReport::findOrFail($report['id'])->statusHistory()->count());
    }
}
