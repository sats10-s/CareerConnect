<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST/GET/PATCH /api/admin/internship-assignments[...] and the
 * reassign/activate/complete/cancel sub-actions - see
 * Api\Admin\InternshipAssignmentController and
 * App\Services\InternshipAssignmentService.
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

    private function makeSuperAdmin(): User
    {
        return $this->makeUser(RoleName::SUPER_ADMIN);
    }

    private function makeFaculty(array $overrides = []): Faculty
    {
        $user = $this->makeUser(RoleName::FACULTY, array_intersect_key($overrides, array_flip(['email', 'is_active'])));

        return Faculty::create(array_merge([
            'user_id' => $user->id,
            'employee_code' => 'FAC'.random_int(1000, 9999),
            'department_id' => $this->department->id,
        ], array_diff_key($overrides, array_flip(['email', 'is_active']))));
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

    private function makeJobPosting(array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Software Engineer I',
            'status' => 'published',
        ], $overrides));
    }

    /**
     * Applies as $studentUser, then fast-forwards the application
     * through the real admin transition endpoint all the way to
     * SELECTED - so every fixture application here is one the app
     * itself could actually have produced.
     */
    private function selectApplication(User $admin, User $studentUser, JobPosting|Internship $opportunity): int
    {
        Sanctum::actingAs($studentUser, ['*']);
        $endpoint = $opportunity instanceof Internship
            ? "/api/student/opportunities/internships/{$opportunity->id}/apply"
            : "/api/student/opportunities/jobs/{$opportunity->id}/apply";
        $applicationId = $this->postJson($endpoint)->assertCreated()->json('data.id');

        Sanctum::actingAs($admin, ['*']);
        foreach (['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED'] as $status) {
            $this->patchJson("/api/admin/applications/{$applicationId}/status", ['status' => $status])->assertOk();
        }

        return $applicationId;
    }

    /**
     * @return array<string, mixed>
     */
    private function createAssignment(User $admin, int $applicationId, int $facultyId, array $overrides = []): array
    {
        Sanctum::actingAs($admin, ['*']);

        return $this->postJson('/api/admin/internship-assignments', array_merge([
            'application_id' => $applicationId,
            'faculty_id' => $facultyId,
        ], $overrides))->assertCreated()->json('data');
    }

    /**
     * A fully assigned (status ASSIGNED) internship assignment, ready
     * to be activated/completed/cancelled/reassigned.
     *
     * @return array{admin: User, faculty: Faculty, student: User, internship: Internship, application_id: int, assignment_id: int}
     */
    private function assignedFixture(): array
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);
        $assignment = $this->createAssignment($admin, $applicationId, $faculty->id);

        return [
            'admin' => $admin,
            'faculty' => $faculty,
            'student' => $studentUser,
            'internship' => $internship,
            'application_id' => $applicationId,
            'assignment_id' => $assignment['id'],
        ];
    }

    // --- create ---------------------------------------------------------

    public function test_admin_can_assign_selected_internship_application(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        $data = $this->createAssignment($admin, $applicationId, $faculty->id);

        $this->assertSame('ASSIGNED', $data['status']);
        $this->assertDatabaseHas('internship_assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
            'internship_id' => $internship->id,
            'status' => 'ASSIGNED',
        ]);
    }

    public function test_super_admin_can_assign(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($superAdmin, $studentUser, $internship);

        $data = $this->createAssignment($superAdmin, $applicationId, $faculty->id);

        $this->assertSame('ASSIGNED', $data['status']);
    }

    public function test_student_cannot_assign(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        Sanctum::actingAs($studentUser, ['*']);
        $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ])->assertStatus(403);
    }

    public function test_faculty_cannot_assign(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        Sanctum::actingAs($faculty->user, ['*']);
        $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_assign(): void
    {
        $this->postJson('/api/admin/internship-assignments', [
            'application_id' => 1,
            'faculty_id' => 1,
        ])->assertStatus(401);
    }

    public function test_non_selected_application_rejected(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        Sanctum::actingAs($studentUser, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply")
            ->assertCreated()->json('data.id');
        // Still APPLIED, never progressed to SELECTED.

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('internship_assignments', ['application_id' => $applicationId]);
    }

    public function test_job_application_rejected(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $jobPosting = $this->makeJobPosting();
        $applicationId = $this->selectApplication($admin, $studentUser, $jobPosting);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('internship_assignments', ['application_id' => $applicationId]);
    }

    public function test_invalid_faculty_id_rejected(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => 999999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['faculty_id']);
    }

    public function test_inactive_faculty_rejected(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty(['is_active' => false]);
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $applicationId,
            'faculty_id' => $faculty->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('internship_assignments', ['application_id' => $applicationId]);
    }

    public function test_duplicate_assignment_is_blocked(): void
    {
        $fixture = $this->assignedFixture();
        $otherFaculty = $this->makeFaculty(['email' => 'other-faculty@careerconnect.edu']);

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $fixture['application_id'],
            'faculty_id' => $otherFaculty->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, InternshipAssignment::where('application_id', $fixture['application_id'])->count());
    }

    public function test_student_and_internship_are_derived_from_application_not_request(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);
        $expectedStudentId = Student::where('user_id', $studentUser->id)->firstOrFail()->id;

        // The request payload cannot even express student_id/internship_id
        // (StoreInternshipAssignmentRequest has no such fields) - this
        // asserts the values that land in the database are the ones
        // derived from the application, not left null/wrong.
        $data = $this->createAssignment($admin, $applicationId, $faculty->id);

        $this->assertDatabaseHas('internship_assignments', [
            'id' => $data['id'],
            'student_id' => $expectedStudentId,
            'internship_id' => $internship->id,
        ]);
    }

    public function test_assigned_by_is_derived_from_authenticated_user(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        $data = $this->createAssignment($admin, $applicationId, $faculty->id);

        $this->assertDatabaseHas('internship_assignments', ['id' => $data['id'], 'assigned_by' => $admin->id]);
    }

    public function test_creation_creates_status_history(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectApplication($admin, $studentUser, $internship);

        $data = $this->createAssignment($admin, $applicationId, $faculty->id);

        $this->assertDatabaseHas('internship_assignment_status_history', [
            'internship_assignment_id' => $data['id'],
            'from_status' => null,
            'to_status' => 'ASSIGNED',
        ]);
        $this->assertSame(1, InternshipAssignment::findOrFail($data['id'])->statusHistory()->count());
    }

    // --- list / detail --------------------------------------------------

    public function test_admin_can_list_assignments(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/internship-assignments');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_by_student(): void
    {
        $fixture = $this->assignedFixture();
        $otherStudentUser = $this->makeStudentUser(['email' => 'other-student@careerconnect.edu']);
        $otherInternship = $this->makeInternship(['title' => 'Other Intern Role']);
        $otherApplicationId = $this->selectApplication($fixture['admin'], $otherStudentUser, $otherInternship);
        $this->createAssignment($fixture['admin'], $otherApplicationId, $fixture['faculty']->id);

        $studentId = Student::where('user_id', $fixture['student']->id)->firstOrFail()->id;

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/internship-assignments?student_id={$studentId}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['assignment_id']);
    }

    public function test_admin_can_filter_by_faculty(): void
    {
        $fixture = $this->assignedFixture();
        $otherFaculty = $this->makeFaculty(['email' => 'other-faculty@careerconnect.edu']);
        $otherStudentUser = $this->makeStudentUser(['email' => 'other-student@careerconnect.edu']);
        $otherInternship = $this->makeInternship(['title' => 'Other Intern Role']);
        $otherApplicationId = $this->selectApplication($fixture['admin'], $otherStudentUser, $otherInternship);
        $this->createAssignment($fixture['admin'], $otherApplicationId, $otherFaculty->id);

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/internship-assignments?faculty_id={$otherFaculty->id}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $fixture = $this->assignedFixture();
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertOk();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/internship-assignments?status=ACTIVE');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['status' => 'ACTIVE']);
    }

    public function test_admin_can_search_by_roll_number(): void
    {
        $fixture = $this->assignedFixture();
        $rollNumber = Student::where('user_id', $fixture['student']->id)->firstOrFail()->roll_number;

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/internship-assignments?search={$rollNumber}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_search_by_company(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/internship-assignments?search=NovaTech');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_search_by_faculty_name(): void
    {
        $fixture = $this->assignedFixture();
        $facultyName = $fixture['faculty']->user->name;

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/internship-assignments?search='.urlencode($facultyName));

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_list_paginates(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();

        for ($i = 1; $i <= 3; $i++) {
            $studentUser = $this->makeStudentUser(['email' => "student{$i}@careerconnect.edu"]);
            $internship = $this->makeInternship(['title' => "Intern Role {$i}"]);
            $applicationId = $this->selectApplication($admin, $studentUser, $internship);
            $this->createAssignment($admin, $applicationId, $faculty->id);
        }

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/internship-assignments?per_page=2');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 3);
        $response->assertJsonPath('data.meta.last_page', 2);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_admin_can_view_assignment_detail(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/internship-assignments/{$fixture['assignment_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['assignment_id']);
        $response->assertJsonPath('data.status', 'ASSIGNED');
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number', 'department'],
            'faculty' => ['id', 'name'],
            'internship',
            'application' => ['id', 'status'],
            'status_history',
            'faculty_history',
            'assigned_by',
        ]]);
    }

    // --- reassign faculty -------------------------------------------------

    public function test_admin_can_reassign_faculty(): void
    {
        $fixture = $this->assignedFixture();
        $newFaculty = $this->makeFaculty(['email' => 'new-faculty@careerconnect.edu']);

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->patchJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/faculty", [
            'faculty_id' => $newFaculty->id,
            'remarks' => 'Original faculty went on leave.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.faculty.id', $newFaculty->id);
        $this->assertDatabaseHas('internship_assignments', ['id' => $fixture['assignment_id'], 'faculty_id' => $newFaculty->id]);
        $this->assertSame(1, InternshipAssignment::where('application_id', $fixture['application_id'])->count());
    }

    public function test_reassignment_creates_faculty_history_and_never_a_second_assignment_row(): void
    {
        $fixture = $this->assignedFixture();
        $newFaculty = $this->makeFaculty(['email' => 'new-faculty@careerconnect.edu']);

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->patchJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/faculty", [
            'faculty_id' => $newFaculty->id,
        ])->assertOk();

        $this->assertDatabaseHas('internship_assignment_faculty_history', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'from_faculty_id' => $fixture['faculty']->id,
            'to_faculty_id' => $newFaculty->id,
        ]);
        $this->assertSame(1, InternshipAssignment::where('application_id', $fixture['application_id'])->count());
    }

    public function test_cannot_reassign_a_completed_assignment(): void
    {
        $fixture = $this->assignedFixture();
        $newFaculty = $this->makeFaculty(['email' => 'new-faculty@careerconnect.edu']);
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertOk();
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        $response = $this->patchJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/faculty", ['faculty_id' => $newFaculty->id]);

        $response->assertStatus(422);
    }

    public function test_cannot_reassign_a_cancelled_assignment(): void
    {
        $fixture = $this->assignedFixture();
        $newFaculty = $this->makeFaculty(['email' => 'new-faculty@careerconnect.edu']);
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'No longer needed.'])->assertOk();

        $response = $this->patchJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/faculty", ['faculty_id' => $newFaculty->id]);

        $response->assertStatus(422);
    }

    // --- activate / complete / cancel lifecycle ---------------------------

    public function test_admin_can_activate_assignment(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ACTIVE');
        $this->assertNotNull($response->json('data.started_at'));
    }

    public function test_cannot_activate_a_non_assigned_assignment(): void
    {
        $fixture = $this->assignedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertOk();

        $response = $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate");

        $response->assertStatus(422);
    }

    public function test_admin_can_complete_an_active_assignment(): void
    {
        $fixture = $this->assignedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertOk();

        $response = $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete", ['remarks' => 'Great performance.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'COMPLETED');
        $this->assertNotNull($response->json('data.ended_at'));
    }

    public function test_cannot_complete_a_non_active_assignment(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete");

        $response->assertStatus(422);
    }

    public function test_admin_can_cancel_an_assigned_assignment(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Internship offer withdrawn.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_admin_can_cancel_an_active_assignment(): void
    {
        $fixture = $this->assignedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertOk();

        $response = $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Student dropped out.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_cancellation_reason_is_required(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_cancelling_does_not_reject_the_original_application(): void
    {
        $fixture = $this->assignedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Cancelled.'])->assertOk();

        $this->assertDatabaseHas('applications', ['id' => $fixture['application_id'], 'status' => 'SELECTED']);
    }

    public function test_terminal_assignment_cannot_transition_again(): void
    {
        $fixture = $this->assignedFixture();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Done.'])->assertOk();

        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertStatus(422);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertStatus(422);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/cancel", ['reason' => 'Again.'])->assertStatus(422);
    }

    // --- security -----------------------------------------------------------

    public function test_idor_faculty_cannot_view_or_manage_assignment_via_admin_routes(): void
    {
        $fixture = $this->assignedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $this->getJson("/api/admin/internship-assignments/{$fixture['assignment_id']}")->assertStatus(403);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/activate")->assertStatus(403);
    }
}
