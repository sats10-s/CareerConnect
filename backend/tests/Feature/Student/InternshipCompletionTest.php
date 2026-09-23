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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/student/internship-completion and GET /api/student/ppo -
 * see Api\Student\InternshipCompletionController/PpoRecordController.
 * There is no create/update/finalize test here at all for either
 * resource - a student has no route that could reach either action
 * (see App\Policies\InternshipCompletionPolicy/PpoRecordPolicy's
 * docblocks: STUDENT is never granted create()/update()/finalize()).
 */
class InternshipCompletionTest extends TestCase
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

        Storage::fake('local');
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

    private function completionFixture(): array
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $completion = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
            'final_remarks' => 'Well done.',
        ])->assertCreated()->json('data');

        $fixture['completion_id'] = $completion['id'];

        return $fixture;
    }

    private function ppoFixture(): array
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $ppo = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
            'package' => 1200000,
        ])->assertCreated()->json('data');

        $fixture['ppo_id'] = $ppo['id'];

        return $fixture;
    }

    // --- Completion ---------------------------------------------------------------

    public function test_student_can_view_own_completion(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/internship-completion');

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['completion_id']);
        $response->assertJsonPath('data.outcome', 'COMPLETED');
    }

    public function test_student_completion_response_omits_faculty_only_fields(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/internship-completion');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayNotHasKey('history', $data);
        $this->assertArrayNotHasKey('student', $data);
    }

    public function test_student_with_no_completion_gets_null_data(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/internship-completion');

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_student_cannot_see_another_students_completion(): void
    {
        $fixture = $this->completionFixture();
        $otherStudent = $this->makeStudentUser();

        Sanctum::actingAs($otherStudent, ['*']);
        $response = $this->getJson('/api/student/internship-completion');

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_student_cannot_create_update_or_finalize_completion_via_faculty_routes(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ])->assertStatus(403);

        $this->getJson('/api/faculty/internship-completions')->assertStatus(403);
    }

    // --- PPO ------------------------------------------------------------------------

    public function test_student_can_view_own_ppo(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/ppo');

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['ppo_id']);
        $response->assertJsonPath('data.designation', 'Software Engineer');
    }

    public function test_student_with_no_ppo_gets_null_data(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/ppo');

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_student_cannot_see_another_students_ppo(): void
    {
        $fixture = $this->ppoFixture();
        $otherStudent = $this->makeStudentUser();

        Sanctum::actingAs($otherStudent, ['*']);
        $response = $this->getJson('/api/student/ppo');

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_student_cannot_create_or_update_ppo_via_faculty_routes(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
        ])->assertStatus(403);
    }

    // --- security -------------------------------------------------------------

    public function test_unauthenticated_cannot_access_student_completion_or_ppo_routes(): void
    {
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/student/internship-completion')->assertStatus(401);
        $this->getJson('/api/student/ppo')->assertStatus(401);
    }
}
