<?php

namespace Tests\Feature\Faculty;

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
 * GET /api/faculty/internship-assignments[/{assignment}] and
 * GET /api/faculty/students - a faculty member's own assignment-based
 * view. PRODUCT RULE - do not weaken this: faculty access is STRICTLY
 * assignment-based (see App\Policies\InternshipAssignmentPolicy and
 * Api\Faculty\InternshipAssignmentController/
 * Api\Faculty\StudentController's docblocks) - a faculty member can
 * never see another faculty's assignments/students, all students, or
 * all internships.
 */
class InternshipAssignmentTest extends TestCase
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

    private function selectApplication(User $studentUser, Internship $internship): int
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
     * @return array<string, mixed>
     */
    private function createAssignment(int $applicationId, int $facultyId): array
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $facultyId,
        ])->assertCreated()->json('data');
    }

    /**
     * @return array{faculty: Faculty, student: User, internship: Internship, assignment_id: int}
     */
    private function assignedFixture(?Faculty $faculty = null): array
    {
        $faculty ??= $this->makeFaculty();
        $studentUser = $this->makeStudentUser(['email' => 'student-'.uniqid().'@careerconnect.edu']);
        $internship = $this->makeInternship(['title' => 'Intern Role '.uniqid()]);
        $applicationId = $this->selectApplication($studentUser, $internship);
        $assignment = $this->createAssignment($applicationId, $faculty->id);

        return ['faculty' => $faculty, 'student' => $studentUser, 'internship' => $internship, 'assignment_id' => $assignment['id']];
    }

    // --- list -------------------------------------------------------------

    public function test_faculty_can_list_own_assignments(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/internship-assignments');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['assignment_id']);
    }

    public function test_faculty_cannot_see_another_facultys_assignments(): void
    {
        $this->assignedFixture(); // belongs to some other faculty
        $ownFaculty = $this->makeFaculty(['email' => 'own-faculty@careerconnect.edu']);

        Sanctum::actingAs($ownFaculty->user, ['*']);
        $response = $this->getJson('/api/faculty/internship-assignments');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    public function test_faculty_can_filter_own_assignments_by_status(): void
    {
        $faculty = $this->makeFaculty();
        $fixture = $this->assignedFixture($faculty);
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertOk();

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/internship-assignments?status=ACTIVE');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_faculty_can_search_own_assignments_by_roll_number(): void
    {
        $faculty = $this->makeFaculty();
        $fixture = $this->assignedFixture($faculty);
        $rollNumber = Student::where('user_id', $fixture['student']->id)->firstOrFail()->roll_number;

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson("/api/faculty/internship-assignments?search={$rollNumber}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_faculty_can_search_own_assignments_by_company(): void
    {
        $faculty = $this->makeFaculty();
        $this->assignedFixture($faculty);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/internship-assignments?search=NovaTech');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    // --- detail -------------------------------------------------------------

    public function test_faculty_can_view_own_assignment(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['assignment_id']);
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number'],
            'internship',
            'application',
            'status_history',
            'faculty_history',
        ]]);
    }

    public function test_faculty_cannot_view_another_facultys_assignment(): void
    {
        $fixture = $this->assignedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}")->assertStatus(403);
    }

    public function test_faculty_detail_does_not_expose_other_faculty_or_admin_internal_fields(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.assigned_by');
        $response->assertJsonMissingPath('data.notes');
    }

    // --- "my students" ------------------------------------------------------

    public function test_faculty_student_list_only_contains_assigned_students(): void
    {
        $faculty = $this->makeFaculty();
        $fixture = $this->assignedFixture($faculty);
        $this->assignedFixture(); // an unrelated student assigned to a different faculty

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/students');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $expectedStudentId = Student::where('user_id', $fixture['student']->id)->firstOrFail()->id;
        $response->assertJsonPath('data.items.0.id', $expectedStudentId);
    }

    public function test_faculty_student_list_search_works(): void
    {
        $faculty = $this->makeFaculty();
        $fixture = $this->assignedFixture($faculty);
        $rollNumber = Student::where('user_id', $fixture['student']->id)->firstOrFail()->roll_number;

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson("/api/faculty/students?search={$rollNumber}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_faculty_student_list_department_filter_works(): void
    {
        $faculty = $this->makeFaculty();
        $this->assignedFixture($faculty);
        $otherDepartment = Department::create(['name' => 'Mechanical Engineering', 'code' => 'MECH']);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson("/api/faculty/students?department_id={$otherDepartment->id}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    public function test_faculty_student_list_never_exposes_unrelated_students(): void
    {
        $faculty = $this->makeFaculty();
        $this->assignedFixture($faculty);
        $this->assignedFixture(); // unrelated

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/students');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.items'));
    }

    // --- security -----------------------------------------------------------

    public function test_faculty_cannot_create_assignment(): void
    {
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($studentUser, $internship);

        Sanctum::actingAs($faculty->user, ['*']);
        $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ])->assertStatus(403);
    }

    public function test_faculty_cannot_reassign_faculty(): void
    {
        $fixture = $this->assignedFixture();
        $otherFaculty = $this->makeFaculty(['email' => 'other@careerconnect.edu']);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/faculty", [
            'faculty_id' => $otherFaculty->id,
        ])->assertStatus(403);
    }

    public function test_faculty_cannot_activate_complete_or_cancel(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertStatus(403);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertStatus(403);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'x'])->assertStatus(403);
    }

    public function test_guest_cannot_access_faculty_routes(): void
    {
        $fixture = $this->assignedFixture();

        // assignedFixture() ends with the auth guard still authenticated
        // as $this->admin (it drives real student/admin endpoints to
        // build the fixture) - see Student\InterviewTest::
        // test_guest_cannot_view_interviews() for the full reasoning;
        // this is the one test here that actually wants a logged-out
        // request, so the guard must be explicitly reset first.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/faculty/internship-assignments')->assertStatus(401);
        $this->getJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}")->assertStatus(401);
        $this->getJson('/api/faculty/students')->assertStatus(401);
    }

    public function test_student_cannot_access_faculty_routes(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson('/api/faculty/internship-assignments')->assertStatus(403);
        $this->getJson('/api/faculty/students')->assertStatus(403);
    }
}
