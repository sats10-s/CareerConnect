<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET/PATCH /api/admin/attendance[...] - see
 * Api\Admin\AttendanceController and App\Services\AttendanceService.
 * There is deliberately no admin create test here - the admin
 * controller has no store action at all (see its own docblock).
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
     * An ACTIVE internship assignment (started_at set), ready to
     * receive attendance.
     *
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

    /**
     * @return array<string, mixed>
     */
    private function markAttendance(User $facultyUser, int $assignmentId, array $overrides = []): array
    {
        Sanctum::actingAs($facultyUser, ['*']);

        return $this->postJson("/api/faculty/internship-assignments/{$assignmentId}/attendance", array_merge([
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ], $overrides))->assertCreated()->json('data');
    }

    private function markedFixture(): array
    {
        $fixture = $this->activatedFixture();
        $attendance = $this->markAttendance($fixture['faculty']->user, $fixture['assignment_id']);
        $fixture['attendance_id'] = $attendance['id'];

        return $fixture;
    }

    // --- list / filter / detail ------------------------------------------

    public function test_admin_can_list_attendance(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/attendance');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_attendance_by_status(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $matching = $this->getJson('/api/admin/attendance?status=PRESENT');
        $nonMatching = $this->getJson('/api/admin/attendance?status=ABSENT');

        $matching->assertOk()->assertJsonPath('data.meta.total', 1);
        $nonMatching->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_admin_can_filter_attendance_by_student(): void
    {
        $fixture = $this->markedFixture();
        $studentId = Student::where('user_id', $fixture['student']->id)->firstOrFail()->id;

        $other = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/attendance?student_id={$studentId}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['attendance_id']);
    }

    public function test_admin_can_filter_attendance_by_faculty(): void
    {
        $fixture = $this->markedFixture();
        $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/attendance?faculty_id={$fixture['faculty']->id}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_attendance_by_date_range(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $inRange = $this->getJson('/api/admin/attendance?from_date='.now()->subDay()->toDateString().'&to_date='.now()->toDateString());
        $outOfRange = $this->getJson('/api/admin/attendance?from_date='.now()->addDays(5)->toDateString().'&to_date='.now()->addDays(10)->toDateString());

        $inRange->assertOk()->assertJsonPath('data.meta.total', 1);
        $outOfRange->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_admin_can_view_attendance_detail(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/attendance/{$fixture['attendance_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['attendance_id']);
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number', 'department'],
            'faculty' => ['id', 'name'],
            'internship',
            'history',
            'marked_by',
        ]]);
    }

    public function test_admin_can_correct_attendance(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->patchJson("/api/admin/attendance/{$fixture['attendance_id']}", [
            'status' => 'ABSENT',
            'remarks' => 'Corrected after review.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ABSENT');
        $this->assertDatabaseHas('attendance', ['id' => $fixture['attendance_id'], 'status' => 'ABSENT']);
    }

    public function test_admin_correction_creates_history(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->patchJson("/api/admin/attendance/{$fixture['attendance_id']}", ['status' => 'LEAVE'])->assertOk();

        $this->assertDatabaseHas('attendance_history', [
            'attendance_id' => $fixture['attendance_id'],
            'old_status' => 'PRESENT',
            'new_status' => 'LEAVE',
        ]);
    }

    public function test_admin_can_correct_attendance_on_a_completed_assignment(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        $response = $this->patchJson("/api/admin/attendance/{$fixture['attendance_id']}", ['status' => 'ABSENT']);

        $response->assertOk();
    }

    public function test_super_admin_access_works(): void
    {
        $fixture = $this->markedFixture();
        $superAdmin = $this->makeSuperAdmin();

        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/admin/attendance')->assertOk();
        $this->getJson("/api/admin/attendance/{$fixture['attendance_id']}")->assertOk();
        $this->patchJson("/api/admin/attendance/{$fixture['attendance_id']}", ['status' => 'ABSENT'])->assertOk();
    }

    // --- security ---------------------------------------------------------

    public function test_student_cannot_access_admin_attendance(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson('/api/admin/attendance')->assertStatus(403);
        $this->getJson("/api/admin/attendance/{$fixture['attendance_id']}")->assertStatus(403);
        $this->patchJson("/api/admin/attendance/{$fixture['attendance_id']}", ['status' => 'ABSENT'])->assertStatus(403);
    }

    public function test_faculty_cannot_access_admin_attendance(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson('/api/admin/attendance')->assertStatus(403);
        $this->getJson("/api/admin/attendance/{$fixture['attendance_id']}")->assertStatus(403);
        $this->patchJson("/api/admin/attendance/{$fixture['attendance_id']}", ['status' => 'ABSENT'])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_attendance(): void
    {
        $fixture = $this->markedFixture();

        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/admin/attendance')->assertStatus(401);
        $this->getJson("/api/admin/attendance/{$fixture['attendance_id']}")->assertStatus(401);
    }
}
