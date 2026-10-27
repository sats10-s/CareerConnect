<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Shared domain-fixture builders for the Excel Sync Center's feature
 * tests, extracted from the exact pattern every other Admin/Faculty/
 * Integration test in this codebase already uses (see
 * tests/Feature/Admin/InternshipCompletionTest.php, the original
 * source of this pattern) - never factories (this codebase has none),
 * always either direct ->create() for simple lookup rows or a real
 * HTTP round-trip through the existing student/admin endpoints for
 * anything with service-layer side effects (application status
 * history, assignment activation, etc.), so an Excel Sync Center test
 * exercises the exact same state a manual admin action would produce.
 */
trait BuildsPlacementFixtures
{
    protected Department $excelDepartment;

    protected Batch $excelBatch;

    protected Company $excelCompany;

    protected function setUpPlacementFixtures(): void
    {
        foreach (RoleName::cases() as $role) {
            if (! Role::where('name', $role->value)->exists()) {
                Role::create(['name' => $role->value, 'label' => $role->label()]);
            }
        }

        $this->excelDepartment = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->excelBatch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
        $this->excelCompany = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);
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

    protected function makeAdmin(): User
    {
        return $this->makeUser(RoleName::PLACEMENT_ADMIN);
    }

    protected function makeSuperAdmin(): User
    {
        return $this->makeUser(RoleName::SUPER_ADMIN);
    }

    protected function makeFaculty(array $overrides = []): Faculty
    {
        $user = $this->makeUser(RoleName::FACULTY, array_intersect_key($overrides, array_flip(['email'])));

        return Faculty::create(array_merge([
            'user_id' => $user->id,
            'employee_code' => 'FAC'.random_int(100000, 999999),
            'department_id' => $this->excelDepartment->id,
        ], array_diff_key($overrides, array_flip(['email']))));
    }

    protected function makeStudentUser(array $overrides = []): User
    {
        $user = $this->makeUser(RoleName::STUDENT, array_intersect_key($overrides, array_flip(['email', 'name'])));

        Student::create(array_merge([
            'user_id' => $user->id,
            'roll_number' => 'CC24'.random_int(100000, 999999),
            'department_id' => $this->excelDepartment->id,
            'batch_id' => $this->excelBatch->id,
            'year' => 3,
            'cgpa' => 8.0,
            'backlogs' => 0,
            'placement_status' => 'NOT_PLACED',
        ], array_diff_key($overrides, array_flip(['email', 'name']))));

        return $user->fresh();
    }

    protected function makeInternship(array $overrides = []): Internship
    {
        return Internship::create(array_merge([
            'company_id' => $this->excelCompany->id,
            'title' => 'Backend Intern',
            'status' => 'published',
        ], $overrides));
    }

    protected function makeJobPosting(array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $this->excelCompany->id,
            'title' => 'Software Engineer',
            'status' => 'published',
        ], $overrides));
    }

    /**
     * Student applies, then the admin advances the application through
     * as many statuses as `$through` lists, via the real
     * PATCH /api/admin/applications/{id}/status endpoint - never a
     * direct Application::create()/update(), so history rows and
     * event dispatch exist exactly as they would for a manual change.
     *
     * @param JobPosting|Internship $opportunity
     * @param list<string> $through
     */
    protected function applyAndAdvance(User $admin, User $studentUser, JobPosting|Internship $opportunity, array $through = []): int
    {
        $segment = $opportunity instanceof Internship ? 'internships' : 'jobs';

        Sanctum::actingAs($studentUser, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/{$segment}/{$opportunity->id}/apply")
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($admin, ['*']);
        foreach ($through as $status) {
            $this->patchJson("/api/admin/applications/{$applicationId}/status", ['status' => $status])->assertOk();
        }

        return $applicationId;
    }

    /**
     * @return array{admin: User, faculty: Faculty, student: User, internship: Internship, application_id: int, assignment_id: int}
     */
    protected function activatedInternshipAssignmentFixture(?User $admin = null): array
    {
        $admin ??= $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->applyAndAdvance($admin, $studentUser, $internship, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']);

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
}
