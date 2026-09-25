<?php

namespace Tests\Feature\Student;

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
 * GET /api/student/internship-assignment - see
 * Api\Student\InternshipAssignmentController. Fixture-building mirrors
 * Student\InternshipCompletionTest exactly (activatedFixture() is
 * copied rather than shared, matching this codebase's existing
 * per-test-class fixture convention - no shared test-support trait
 * exists for it yet).
 */
class InternshipAssignmentTest extends TestCase
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
    private function assignedFixture(): array
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

        return [
            'admin' => $admin,
            'faculty' => $faculty,
            'student' => $studentUser,
            'internship' => $internship,
            'application_id' => $applicationId,
            'assignment_id' => $assignmentId,
        ];
    }

    public function test_student_can_view_own_assignment(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/internship-assignment');

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['assignment_id']);
        $response->assertJsonPath('data.status', 'ASSIGNED');
        $response->assertJsonPath('data.internship.title', 'Backend Intern');
        $response->assertJsonPath('data.faculty.id', $fixture['faculty']->id);
    }

    public function test_assignment_response_omits_admin_only_fields(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $data = $this->getJson('/api/student/internship-assignment')->assertOk()->json('data');

        $this->assertArrayNotHasKey('notes', $data);
        $this->assertArrayNotHasKey('assigned_by', $data);
    }

    public function test_student_with_no_assignment_gets_null_data(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/internship-assignment');

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_student_cannot_see_another_students_assignment(): void
    {
        $this->assignedFixture();
        $otherStudent = $this->makeStudentUser();

        Sanctum::actingAs($otherStudent, ['*']);
        $response = $this->getJson('/api/student/internship-assignment');

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_faculty_and_admin_are_rejected_by_the_student_route(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson('/api/student/internship-assignment')->assertStatus(403);

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->getJson('/api/student/internship-assignment')->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_the_route(): void
    {
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/student/internship-assignment')->assertStatus(401);
    }
}
