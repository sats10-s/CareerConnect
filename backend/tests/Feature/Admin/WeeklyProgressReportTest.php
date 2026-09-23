<?php

namespace Tests\Feature\Admin;

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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/admin/weekly-reports[...] - see
 * Api\Admin\WeeklyProgressReportController. There is deliberately no
 * admin create/update/submit/review test here - the admin controller
 * has no write action at all (see its own docblock and the module
 * spec's "do not add unnecessary admin write privileges").
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

    private function makeSuperAdmin(): User
    {
        return $this->makeUser(RoleName::SUPER_ADMIN);
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

    private function submittedFixture(): array
    {
        $fixture = $this->activatedFixture();
        [$start, $end] = $this->weekDates($fixture['started_at'], 1);

        Sanctum::actingAs($fixture['student'], ['*']);
        $report = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Worked on backend APIs during week 1.',
        ])->assertCreated()->json('data');
        $fixture['report_id'] = $report['id'];

        $this->postJson("/api/student/weekly-reports/{$fixture['report_id']}/submit")->assertOk();

        return $fixture;
    }

    // --- list / filter / view -------------------------------------------------

    public function test_admin_can_list_all_weekly_reports(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/weekly-reports');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $matching = $this->getJson('/api/admin/weekly-reports?status=SUBMITTED');
        $nonMatching = $this->getJson('/api/admin/weekly-reports?status=APPROVED');

        $matching->assertOk()->assertJsonPath('data.meta.total', 1);
        $nonMatching->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_admin_can_filter_by_faculty(): void
    {
        $fixture = $this->submittedFixture();
        $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/weekly-reports?faculty_id={$fixture['faculty']->id}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_by_student(): void
    {
        $fixture = $this->submittedFixture();
        $studentId = Student::where('user_id', $fixture['student']->id)->firstOrFail()->id;
        $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/weekly-reports?student_id={$studentId}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['report_id']);
    }

    public function test_admin_can_filter_by_week_number(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $matching = $this->getJson('/api/admin/weekly-reports?week_number=1');
        $nonMatching = $this->getJson('/api/admin/weekly-reports?week_number=2');

        $matching->assertOk()->assertJsonPath('data.meta.total', 1);
        $nonMatching->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_admin_can_view_report_detail(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/weekly-reports/{$fixture['report_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['report_id']);
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number', 'department'],
            'faculty' => ['id', 'name'],
            'internship',
            'history',
        ]]);
    }

    public function test_super_admin_access_works(): void
    {
        $fixture = $this->submittedFixture();
        $superAdmin = $this->makeSuperAdmin();

        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/admin/weekly-reports')->assertOk();
        $this->getJson("/api/admin/weekly-reports/{$fixture['report_id']}")->assertOk();
    }

    // --- no admin write access -------------------------------------------------

    public function test_admin_cannot_review_reports(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/faculty/weekly-reports/{$fixture['report_id']}/review", ['action' => 'UNDER_REVIEW'])->assertStatus(403);
    }

    public function test_admin_cannot_create_or_update_reports(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->patchJson("/api/student/weekly-reports/{$fixture['report_id']}", ['work_done' => 'Admin editing content.'])->assertStatus(403);
        [$start, $end] = $this->weekDates($fixture['started_at'], 2);
        $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 2,
            'week_start_date' => $start,
            'week_end_date' => $end,
            'work_done' => 'Admin creating on behalf of student.',
        ])->assertStatus(403);
    }

    // --- security -------------------------------------------------------------

    public function test_student_cannot_access_admin_weekly_report_routes(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson('/api/admin/weekly-reports')->assertStatus(403);
        $this->getJson("/api/admin/weekly-reports/{$fixture['report_id']}")->assertStatus(403);
    }

    public function test_faculty_cannot_access_admin_weekly_report_routes(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson('/api/admin/weekly-reports')->assertStatus(403);
        $this->getJson("/api/admin/weekly-reports/{$fixture['report_id']}")->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_weekly_report_routes(): void
    {
        $fixture = $this->submittedFixture();

        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/admin/weekly-reports')->assertStatus(401);
        $this->getJson("/api/admin/weekly-reports/{$fixture['report_id']}")->assertStatus(401);
    }
}
