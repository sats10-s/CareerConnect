<?php

namespace Tests\Feature\Faculty;

use App\Enums\RoleName;
use App\Models\Attendance;
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
 * GET/POST/PATCH /api/faculty/attendance[...] and
 * /api/faculty/internship-assignments/{assignment}/attendance - see
 * Api\Faculty\AttendanceController and App\Services\AttendanceService.
 * Also covers the core PRESENT/ABSENT/LEAVE, time-consistency,
 * duplicate, date-range and status-transition business rules, since
 * they are all exercised through the faculty marking endpoint.
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

    private function markedFixture(): array
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $attendance = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated()->json('data');

        $fixture['attendance_id'] = $attendance['id'];

        return $fixture;
    }

    // --- marking ----------------------------------------------------------

    public function test_faculty_can_mark_attendance_for_own_assignment(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'PRESENT');
        $this->assertDatabaseHas('attendance', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'status' => 'PRESENT',
            'marked_by' => $fixture['faculty']->user->id,
        ]);
    }

    public function test_marking_attendance_creates_history(): void
    {
        $fixture = $this->markedFixture();

        $this->assertDatabaseHas('attendance_history', [
            'attendance_id' => $fixture['attendance_id'],
            'old_status' => null,
            'new_status' => 'PRESENT',
        ]);
        $this->assertSame(1, Attendance::findOrFail($fixture['attendance_id'])->history()->count());
    }

    public function test_present_status_is_accepted(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated()->assertJsonPath('data.status', 'PRESENT');
    }

    public function test_absent_status_is_accepted(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'ABSENT',
        ])->assertCreated()->assertJsonPath('data.status', 'ABSENT');
    }

    public function test_leave_status_is_accepted(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'LEAVE',
        ])->assertCreated()->assertJsonPath('data.status', 'LEAVE');
    }

    public function test_present_with_times_is_accepted(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
            'check_in_time' => '09:00',
            'check_out_time' => '17:00',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.check_in_time', '09:00:00');
        $response->assertJsonPath('data.check_out_time', '17:00:00');
    }

    public function test_absent_with_times_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'ABSENT',
            'check_in_time' => '09:00',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_leave_with_times_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'LEAVE',
            'check_out_time' => '17:00',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_checkout_before_checkin_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
            'check_in_time' => '17:00',
            'check_out_time' => '09:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['check_out_time']);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'ON_LEAVE_SICK',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_invalid_check_in_time_format_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
            'check_in_time' => 'not-a-time',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['check_in_time']);
    }

    public function test_duplicate_date_attendance_is_rejected(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'ABSENT',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Attendance::where('internship_assignment_id', $fixture['assignment_id'])->count());
    }

    public function test_future_date_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->addDays(2)->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attendance_date']);
    }

    public function test_date_before_internship_start_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->subDays(30)->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attendance_date']);
    }

    public function test_cannot_mark_attendance_for_a_cancelled_assignment(): void
    {
        $fixture = $this->activatedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Withdrawn.'])->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_cannot_mark_attendance_for_a_completed_assignment(): void
    {
        $fixture = $this->activatedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_faculty_cannot_mark_attendance_for_another_facultys_student(): void
    {
        $fixture = $this->activatedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    // --- update -------------------------------------------------------------

    public function test_faculty_can_update_own_attendance(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/attendance/{$fixture['attendance_id']}", [
            'status' => 'ABSENT',
            'remarks' => 'Marked incorrectly, corrected.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ABSENT');
        $this->assertDatabaseHas('attendance', ['id' => $fixture['attendance_id'], 'status' => 'ABSENT']);
    }

    public function test_updating_status_to_absent_clears_existing_times(): void
    {
        $fixture = $this->activatedFixture();
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $attendance = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
            'check_in_time' => '09:00',
            'check_out_time' => '17:00',
        ])->assertCreated()->json('data');

        $response = $this->patchJson("/api/faculty/attendance/{$attendance['id']}", ['status' => 'ABSENT']);

        $response->assertOk();
        $this->assertDatabaseHas('attendance', [
            'id' => $attendance['id'],
            'status' => 'ABSENT',
            'check_in_time' => null,
            'check_out_time' => null,
        ]);
    }

    public function test_update_cannot_change_assignment_or_date(): void
    {
        $fixture = $this->markedFixture();
        $other = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/attendance/{$fixture['attendance_id']}", [
            'status' => 'ABSENT',
            'internship_assignment_id' => $other['assignment_id'],
            'attendance_date' => now()->subDay()->toDateString(),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance', [
            'id' => $fixture['attendance_id'],
            'internship_assignment_id' => $fixture['assignment_id'],
            'attendance_date' => now()->toDateString(),
        ]);
    }

    public function test_faculty_cannot_update_attendance_after_assignment_completion(): void
    {
        $fixture = $this->markedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/attendance/{$fixture['attendance_id']}", ['status' => 'ABSENT']);

        $response->assertStatus(422);
    }

    public function test_faculty_cannot_update_another_facultys_attendance(): void
    {
        $fixture = $this->markedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder2@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $response = $this->patchJson("/api/faculty/attendance/{$fixture['attendance_id']}", ['status' => 'ABSENT']);

        $response->assertStatus(403);
    }

    // --- list / filter / search ---------------------------------------------

    public function test_faculty_can_list_own_attendance(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/attendance');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_faculty_cannot_see_another_facultys_attendance_in_list(): void
    {
        $fixture = $this->markedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder3@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $response = $this->getJson('/api/faculty/attendance');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    public function test_faculty_can_filter_by_date(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $matching = $this->getJson('/api/faculty/attendance?from_date='.now()->toDateString().'&to_date='.now()->toDateString());
        $nonMatching = $this->getJson('/api/faculty/attendance?from_date='.now()->subDays(10)->toDateString().'&to_date='.now()->subDays(5)->toDateString());

        $matching->assertOk()->assertJsonPath('data.meta.total', 1);
        $nonMatching->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_faculty_can_filter_by_status(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $matching = $this->getJson('/api/faculty/attendance?status=PRESENT');
        $nonMatching = $this->getJson('/api/faculty/attendance?status=LEAVE');

        $matching->assertOk()->assertJsonPath('data.meta.total', 1);
        $nonMatching->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_faculty_can_search_students(): void
    {
        $fixture = $this->markedFixture();
        $rollNumber = Student::where('user_id', $fixture['student']->id)->firstOrFail()->roll_number;

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/attendance?search={$rollNumber}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_student_id_filter_cannot_escape_faculty_scope(): void
    {
        $fixture = $this->markedFixture();
        $otherFixtureOwnedByAnotherFaculty = $this->markedFixture();
        $otherStudentId = Student::where('user_id', $otherFixtureOwnedByAnotherFaculty['student']->id)->firstOrFail()->id;

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/attendance?student_id={$otherStudentId}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    // --- assignment-scoped list + summary ------------------------------------

    public function test_faculty_can_view_own_assignment_attendance_and_summary(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance");

        $response->assertOk();
        $response->assertJsonPath('data.summary.total_days', 1);
        $response->assertJsonPath('data.summary.present_days', 1);
        $response->assertJsonPath('data.summary.attendance_percentage', 100.0);
        $this->assertCount(1, $response->json('data.records'));
    }

    public function test_faculty_cannot_access_another_facultys_assignment_attendance(): void
    {
        $fixture = $this->markedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder4@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance")->assertStatus(403);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertStatus(403);
    }

    // --- security -------------------------------------------------------------

    public function test_guest_cannot_access_faculty_attendance_routes(): void
    {
        $fixture = $this->markedFixture();

        // markedFixture() ends with the auth guard authenticated as the
        // faculty user (it drives real student/admin/faculty endpoints
        // to build the fixture) - see Student\InterviewTest's own
        // docblock for the full reasoning; this is the one test here
        // that actually wants a logged-out request, so the guard must
        // be explicitly reset first.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/faculty/attendance')->assertStatus(401);
        $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance")->assertStatus(401);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [])->assertStatus(401);
        $this->patchJson("/api/faculty/attendance/{$fixture['attendance_id']}", [])->assertStatus(401);
    }

    public function test_student_cannot_access_faculty_attendance_routes(): void
    {
        $fixture = $this->markedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson('/api/faculty/attendance')->assertStatus(403);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertStatus(403);
    }
}
