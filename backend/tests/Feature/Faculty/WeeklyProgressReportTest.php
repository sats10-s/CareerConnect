<?php

namespace Tests\Feature\Faculty;

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
 * /api/faculty/weekly-reports[...] and
 * /api/faculty/internship-assignments/{assignment}/weekly-reports - see
 * Api\Faculty\WeeklyProgressReportController and
 * App\Services\WeeklyProgressReportService. There is no store()/
 * update() here at all - a faculty member never writes report content,
 * only moves a report through the review lifecycle (see that
 * controller's own docblock).
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

    private function createDraft(array $fixture, int $weekNumber = 1): array
    {
        [$start, $end] = $this->weekDates($fixture['started_at'], $weekNumber);

        Sanctum::actingAs($fixture['student'], ['*']);

        return $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => $weekNumber,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => "Worked on backend APIs during week {$weekNumber}.",
            'learning_outcomes' => 'Learned about Laravel service layers.',
        ])->assertCreated()->json('data');
    }

    private function submittedFixture(): array
    {
        $fixture = $this->activatedFixture();
        $report = $this->createDraft($fixture);
        $fixture['report_id'] = $report['id'];

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

    // --- list / view ---------------------------------------------------------

    public function test_faculty_can_list_assigned_students_reports(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/weekly-reports');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_faculty_can_view_report_detail(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/weekly-reports/{$fixture['report_id']}");

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number'],
            'faculty' => ['id', 'name'],
            'internship',
            'history',
        ]]);
    }

    public function test_faculty_can_view_reports_for_assignment(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/weekly-reports");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.records'));
    }

    // --- review lifecycle ------------------------------------------------------

    public function test_faculty_can_move_submitted_to_under_review(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'UNDER_REVIEW']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->assertNotNull($response->json('data.faculty_reviewed_at'));
        $this->assertDatabaseHas('weekly_progress_report_status_history', [
            'weekly_progress_report_id' => $fixture['report_id'],
            'from_status' => 'SUBMITTED',
            'to_status' => 'UNDER_REVIEW',
        ]);
    }

    public function test_faculty_can_approve_under_review_report(): void
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", [
            'action' => 'APPROVED',
            'faculty_review_remarks' => 'Great progress this week.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');
        $response->assertJsonPath('data.faculty_review_remarks', 'Great progress this week.');
        $this->assertDatabaseHas('weekly_progress_report_status_history', [
            'weekly_progress_report_id' => $fixture['report_id'],
            'from_status' => 'UNDER_REVIEW',
            'to_status' => 'APPROVED',
        ]);
    }

    public function test_faculty_can_request_changes_with_remarks(): void
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", [
            'action' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Please expand on the challenges section.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CHANGES_REQUESTED');
        $response->assertJsonPath('data.faculty_review_remarks', 'Please expand on the challenges section.');
    }

    public function test_faculty_cannot_request_changes_without_remarks(): void
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'CHANGES_REQUESTED']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['faculty_review_remarks']);
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $fixture['report_id'], 'status' => 'UNDER_REVIEW']);
    }

    public function test_starting_review_does_not_blank_out_a_previous_rounds_remarks(): void
    {
        // CHANGES_REQUESTED -> student resubmits -> faculty starts a
        // new review round: startReview() always passes null remarks,
        // which must never overwrite the prior round's
        // faculty_review_remarks - see WeeklyProgressReportService::
        // transition()'s own docblock.
        $fixture = $this->underReviewFixture();
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", [
            'action' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'First round of feedback.',
        ])->assertOk();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Addressed feedback.'])->assertOk();
        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'UNDER_REVIEW']);

        $response->assertOk();
        $response->assertJsonPath('data.faculty_review_remarks', 'First round of feedback.');
    }

    public function test_faculty_cannot_approve_directly_from_submitted(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'APPROVED']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $fixture['report_id'], 'status' => 'SUBMITTED']);
    }

    public function test_faculty_cannot_modify_approved_report(): void
    {
        $fixture = $this->approvedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", [
            'action' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Trying to reopen an approved report.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $fixture['report_id'], 'status' => 'APPROVED']);
    }

    public function test_review_does_not_modify_students_report_content(): void
    {
        $fixture = $this->underReviewFixture();
        $original = WeeklyProgressReport::findOrFail($fixture['report_id']);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", [
            'action' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        $reloaded = $original->fresh();
        $this->assertSame($original->work_done, $reloaded->work_done);
        $this->assertSame($original->week_start_date->toDateString(), $reloaded->week_start_date->toDateString());
        $this->assertSame($original->week_number, $reloaded->week_number);
    }

    // --- authorization / IDOR ------------------------------------------------

    public function test_faculty_cannot_access_another_facultys_students_reports(): void
    {
        $fixture = $this->submittedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder-faculty@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $this->getJson("/api/faculty/weekly-reports/{$fixture['report_id']}")->assertStatus(403);
        $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/weekly-reports")->assertStatus(403);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'UNDER_REVIEW'])->assertStatus(403);
    }

    public function test_faculty_list_never_includes_another_facultys_reports(): void
    {
        $fixture = $this->submittedFixture();
        $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/weekly-reports');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['report_id']);
    }

    public function test_student_id_filter_cannot_escape_faculty_scope(): void
    {
        $fixture = $this->submittedFixture();
        $otherFixtureOwnedByAnotherFaculty = $this->submittedFixture();
        $otherStudentId = Student::where('user_id', $otherFixtureOwnedByAnotherFaculty['student']->id)->firstOrFail()->id;

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/weekly-reports?student_id={$otherStudentId}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    public function test_faculty_cannot_create_report(): void
    {
        $fixture = $this->activatedFixture();
        [$start, $end] = $this->weekDates($fixture['started_at'], 1);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Faculty trying to create on behalf of student.',
        ])->assertStatus(403);
    }

    public function test_faculty_cannot_update_report_content(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Faculty editing student content.'])->assertStatus(403);
    }

    public function test_faculty_cannot_submit_report(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertStatus(403);
    }

    public function test_faculty_cannot_access_admin_weekly_report_routes(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson('/api/admin/weekly-reports')->assertStatus(403);
        $this->getJson("/api/admin/weekly-reports/{$fixture['report_id']}")->assertStatus(403);
    }

    // --- security -------------------------------------------------------------

    public function test_guest_cannot_access_faculty_weekly_report_routes(): void
    {
        $fixture = $this->submittedFixture();

        // submittedFixture() ends with the auth guard authenticated as
        // the student (it drives real student/admin endpoints to build
        // the fixture) - the guard must be explicitly reset for this
        // one test that wants a genuinely logged-out request.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/faculty/weekly-reports')->assertStatus(401);
        $this->getJson("/api/faculty/weekly-reports/{$fixture['report_id']}")->assertStatus(401);
        $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/weekly-reports")->assertStatus(401);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", [])->assertStatus(401);
    }
}
