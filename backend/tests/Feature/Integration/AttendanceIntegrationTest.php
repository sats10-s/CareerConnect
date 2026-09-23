<?php

namespace Tests\Feature\Integration;

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
 * Cross-module integration for Attendance Management: confirms the
 * full chain - Student -> Internship opportunity -> Application ->
 * SELECTED -> Internship Assignment -> ACTIVE -> Attendance - works
 * end to end, that a cancelled/completed assignment blocks new
 * attendance, and that marking attendance never mutates the
 * application's own status or the internship's publication status.
 * Nothing here inserts directly into applications/internship_assignments/
 * attendance - every fixture is produced through the real student/
 * admin/faculty endpoints, exactly like
 * InternshipAssignmentIntegrationTest.
 */
class AttendanceIntegrationTest extends TestCase
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
     * @return array{faculty: Faculty, student: User, internship: Internship, application_id: int, assignment_id: int}
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

        return [
            'faculty' => $faculty,
            'student' => $studentUser,
            'internship' => $internship,
            'application_id' => $applicationId,
            'assignment_id' => $assignmentId,
        ];
    }

    // --- the core chain -----------------------------------------------------

    public function test_selected_application_flows_into_assignment_activation_and_attendance(): void
    {
        $fixture = $this->activatedAssignment();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendance', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'status' => 'PRESENT',
        ]);
    }

    // --- assignment lifecycle gates attendance -------------------------------

    public function test_cancelled_assignment_blocks_new_attendance(): void
    {
        $fixture = $this->activatedAssignment();
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Withdrawn.'])->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_completed_assignment_blocks_new_attendance(): void
    {
        $fixture = $this->activatedAssignment();
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_historical_attendance_remains_readable_after_completion(): void
    {
        $fixture = $this->activatedAssignment();
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated();

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance")
            ->assertOk()
            ->assertJsonPath('data.summary.total_days', 1);
    }

    // --- side effects -------------------------------------------------------

    public function test_marking_attendance_does_not_alter_the_application_status(): void
    {
        $fixture = $this->activatedAssignment();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated();

        $this->assertDatabaseHas('applications', ['id' => $fixture['application_id'], 'status' => 'SELECTED']);
    }

    public function test_marking_attendance_does_not_alter_the_internships_publication_status(): void
    {
        $fixture = $this->activatedAssignment();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated();

        $this->assertDatabaseHas('internships', ['id' => $fixture['internship']->id, 'status' => 'published']);
    }

    public function test_marking_attendance_does_not_alter_the_assignment_status(): void
    {
        $fixture = $this->activatedAssignment();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated();

        $this->assertDatabaseHas('internship_assignments', ['id' => $fixture['assignment_id'], 'status' => 'ACTIVE']);
    }
}
