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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/student/attendance[...] and
 * /api/student/internship-assignments/{assignment}/attendance - see
 * Api\Student\AttendanceController. A student can only ever read - no
 * create/update/delete routes exist for students at all.
 */
class AttendanceTest extends TestCase
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
     * @return array{admin: User, faculty: Faculty, student: User, internship: Internship, application_id: int, assignment_id: int}
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

        return [
            'admin' => $admin,
            'faculty' => $faculty,
            'student' => $studentUser,
            'internship' => $internship,
            'application_id' => $applicationId,
            'assignment_id' => $assignmentId,
        ];
    }

    private function mark(Faculty $faculty, int $assignmentId, array $overrides = []): array
    {
        Sanctum::actingAs($faculty->user, ['*']);

        return $this->postJson("/api/faculty/internship-assignments/{$assignmentId}/attendance", array_merge([
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ], $overrides))->assertCreated()->json('data');
    }

    private function markedFixture(): array
    {
        $fixture = $this->activatedFixture();
        $attendance = $this->mark($fixture['faculty'], $fixture['assignment_id']);
        $fixture['attendance_id'] = $attendance['id'];

        return $fixture;
    }

    // --- own attendance -----------------------------------------------------

    public function test_student_can_view_own_attendance_list(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/attendance');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_student_can_view_own_attendance_detail(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/attendance/{$fixture['attendance_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['attendance_id']);
        // Student-facing detail never exposes correction history or who
        // marked/corrected the record.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('history', $data);
        $this->assertArrayNotHasKey('marked_by', $data);
    }

    public function test_student_can_view_own_summary(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/attendance");

        $response->assertOk();
        $response->assertJsonPath('data.summary.total_days', 1);
        $response->assertJsonPath('data.summary.present_days', 1);
        $response->assertJsonPath('data.summary.absent_days', 0);
        $response->assertJsonPath('data.summary.leave_days', 0);
        $response->assertJsonPath('data.summary.attendance_percentage', 100.0);
        $response->assertJsonPath('data.summary.current_streak', 1);
        $this->assertCount(1, $response->json('data.records'));
    }

    public function test_zero_attendance_returns_zero_percent(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/attendance");

        $response->assertOk();
        $response->assertJsonPath('data.summary.total_days', 0);
        $response->assertJsonPath('data.summary.attendance_percentage', 0);
    }

    public function test_percentage_is_calculated_correctly(): void
    {
        $fixture = $this->activatedFixture();
        // Activation sets started_at to "now" (today), which would only
        // leave "today" as a valid attendance date - push it back so
        // this test can mark several distinct days within a still-valid
        // period. This bypasses the API purely for test setup; it does
        // not exercise (and is not testing) the activate endpoint.
        InternshipAssignment::whereKey($fixture['assignment_id'])->update(['started_at' => now()->subDays(10)]);

        $this->mark($fixture['faculty'], $fixture['assignment_id'], ['attendance_date' => now()->subDays(2)->toDateString(), 'status' => 'PRESENT']);
        $this->mark($fixture['faculty'], $fixture['assignment_id'], ['attendance_date' => now()->subDays(1)->toDateString(), 'status' => 'ABSENT']);
        $this->mark($fixture['faculty'], $fixture['assignment_id'], ['attendance_date' => now()->toDateString(), 'status' => 'LEAVE']);

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/attendance");

        $response->assertOk();
        $response->assertJsonPath('data.summary.total_days', 3);
        $response->assertJsonPath('data.summary.present_days', 1);
        $response->assertJsonPath('data.summary.absent_days', 1);
        $response->assertJsonPath('data.summary.leave_days', 1);
        // 1 present / 3 marked * 100, rounded to 2dp.
        $response->assertJsonPath('data.summary.attendance_percentage', 33.33);
    }

    // --- authorization / IDOR ------------------------------------------------

    public function test_student_cannot_create_attendance(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertStatus(403);
    }

    public function test_student_cannot_update_attendance(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/faculty/attendance/{$fixture['attendance_id']}", ['status' => 'ABSENT'])->assertStatus(403);
    }

    public function test_student_cannot_view_another_students_attendance(): void
    {
        $fixture = $this->markedFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student@careerconnect.edu']);

        Sanctum::actingAs($intruder, ['*']);
        $this->getJson("/api/student/attendance/{$fixture['attendance_id']}")->assertStatus(403);
    }

    public function test_student_cannot_access_another_assignment(): void
    {
        $fixture = $this->markedFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student2@careerconnect.edu']);

        Sanctum::actingAs($intruder, ['*']);
        $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/attendance")->assertStatus(403);
    }

    public function test_student_attendance_list_never_includes_another_students_records(): void
    {
        $fixture = $this->markedFixture();
        $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/attendance');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['attendance_id']);
    }

    public function test_student_cannot_access_faculty_or_admin_attendance_endpoints(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson('/api/faculty/attendance')->assertStatus(403);
        $this->getJson('/api/admin/attendance')->assertStatus(403);
        $this->getJson("/api/admin/attendance/{$fixture['attendance_id']}")->assertStatus(403);
    }

    public function test_guest_cannot_access_student_attendance_routes(): void
    {
        $fixture = $this->markedFixture();

        // markedFixture() ends with the auth guard authenticated as the
        // faculty user (it drives real admin/faculty endpoints to build
        // the fixture) - the guard must be explicitly reset for the one
        // test here that wants a genuinely logged-out request.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/student/attendance')->assertStatus(401);
        $this->getJson("/api/student/attendance/{$fixture['attendance_id']}")->assertStatus(401);
        $this->getJson("/api/student/internship-assignments/{$fixture['assignment_id']}/attendance")->assertStatus(401);
    }
}
