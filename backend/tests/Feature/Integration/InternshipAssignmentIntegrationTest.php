<?php

namespace Tests\Feature\Integration;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-module integration for Internship Assignment + Faculty
 * Coordination: confirms the full chain -
 * Student -> Internship opportunity -> Application -> SELECTED ->
 * Internship Assignment -> Faculty Coordinator - works end to end,
 * including through a Placement Drive
 * (Company -> Placement Drive -> Internship -> Application -> SELECTED
 * -> Assignment), and that creating an assignment never mutates the
 * application's own status or the internship's own publication
 * status. Nothing here inserts directly into applications/interviews/
 * internship_assignments - every fixture is produced through the real
 * student/admin endpoints, exactly like Admin\InternshipAssignmentTest/
 * Faculty\InternshipAssignmentTest and the Placement Drive module's
 * own integration test.
 */
class InternshipAssignmentIntegrationTest extends TestCase
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

    private function makeJobPosting(array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Software Engineer I',
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

    // --- the core chain -----------------------------------------------------

    public function test_selected_application_flows_into_an_internship_assignment_with_a_faculty_coordinator(): void
    {
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectInternshipApplication($studentUser, $internship);

        $data = $this->createAssignment($applicationId, $faculty->id);

        $expectedStudentId = Student::where('user_id', $studentUser->id)->firstOrFail()->id;

        $this->assertDatabaseHas('internship_assignments', [
            'id' => $data['id'],
            'application_id' => $applicationId,
            'internship_id' => $internship->id,
            'student_id' => $expectedStudentId,
            'faculty_id' => $faculty->id,
            'status' => 'ASSIGNED',
        ]);
    }

    public function test_placement_drive_internship_application_selected_assignment_full_chain(): void
    {
        $faculty = $this->makeFaculty();
        $internship = $this->makeInternship();

        // Company -> Placement Drive -> Internship.
        Sanctum::actingAs($this->admin, ['*']);
        $driveId = $this->postJson('/api/admin/placement-drives', [
            'company_id' => $this->company->id,
            'title' => 'Winter Internship Drive',
            'drive_type' => 'INTERNSHIP',
            'scheduled_at' => now()->addDays(10)->toIso8601String(),
            'application_deadline' => now()->addDays(5)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/placement-drives/{$driveId}/opportunities", [
            'opportunity_type' => 'internship',
            'opportunity_id' => $internship->id,
        ])->assertCreated();

        $this->postJson("/api/admin/placement-drives/{$driveId}/publish")->assertOk();

        // Internship -> Application -> SELECTED (applications always go
        // through the opportunity itself - never a second application
        // system inside the drive, per both modules' architecture rule).
        $studentUser = $this->makeStudentUser();
        $applicationId = $this->selectInternshipApplication($studentUser, $internship);

        // SELECTED -> Assignment -> Faculty.
        $data = $this->createAssignment($applicationId, $faculty->id);
        $this->assertSame('ASSIGNED', $data['status']);

        // The drive's own aggregate stats (via the EXISTING
        // ComputesPlacementDriveStats join) reflect this same
        // application/selection, confirming the two modules tie
        // together through the same underlying `applications` table
        // rather than duplicating anything.
        Sanctum::actingAs($this->admin, ['*']);
        $driveDetail = $this->getJson("/api/admin/placement-drives/{$driveId}")->assertOk();
        $driveDetail->assertJsonPath('data.application_count', 1);
        $driveDetail->assertJsonPath('data.selected_count', 1);

        $this->assertDatabaseHas('internship_assignments', [
            'application_id' => $applicationId,
            'internship_id' => $internship->id,
            'faculty_id' => $faculty->id,
        ]);
    }

    // --- assignment creation leaves everything else untouched ---------------

    public function test_creating_an_assignment_does_not_alter_the_application_status(): void
    {
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $applicationId = $this->selectInternshipApplication($studentUser, $internship);

        $this->createAssignment($applicationId, $faculty->id);

        $this->assertDatabaseHas('applications', ['id' => $applicationId, 'status' => 'SELECTED']);
    }

    public function test_creating_an_assignment_does_not_alter_the_internships_publication_status(): void
    {
        $faculty = $this->makeFaculty();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship(['status' => 'published']);
        $applicationId = $this->selectInternshipApplication($studentUser, $internship);

        $this->createAssignment($applicationId, $faculty->id);

        $this->assertDatabaseHas('internships', ['id' => $internship->id, 'status' => 'published']);
    }

    // --- existing lifecycles remain unaffected -------------------------------

    public function test_application_and_interview_lifecycle_still_works_independently_of_assignments(): void
    {
        // One internship application gets fully assigned...
        $faculty = $this->makeFaculty();
        $assignedStudent = $this->makeStudentUser(['email' => 'assigned-student@careerconnect.edu']);
        $internship = $this->makeInternship();
        $assignedApplicationId = $this->selectInternshipApplication($assignedStudent, $internship);
        $this->createAssignment($assignedApplicationId, $faculty->id);

        // ...while a completely separate JOB application, untouched by
        // this module, still runs through its full existing
        // Application + Interview lifecycle exactly as before this
        // module existed.
        $jobPosting = $this->makeJobPosting();
        $otherStudent = $this->makeStudentUser(['email' => 'other-student@careerconnect.edu']);

        Sanctum::actingAs($otherStudent, ['*']);
        $jobApplicationId = $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/applications/{$jobApplicationId}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/admin/applications/{$jobApplicationId}/status", ['status' => 'SHORTLISTED'])->assertOk();

        $interview = $this->postJson("/api/admin/applications/{$jobApplicationId}/interviews", [
            'round' => 1,
            'title' => 'Technical Interview',
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
            'duration_minutes' => 60,
            'mode' => 'ONLINE',
            'meeting_url' => 'https://meet.example.com/room123',
        ])->assertCreated()->json('data');

        $this->postJson("/api/admin/interviews/{$interview['id']}/complete", ['remarks' => 'Went well.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->patchJson("/api/admin/applications/{$jobApplicationId}/status", ['status' => 'SELECTED'])->assertOk();
        $this->assertDatabaseHas('applications', ['id' => $jobApplicationId, 'status' => 'SELECTED']);

        // A job application can never receive an internship assignment.
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/admin/internship-assignments', [
            'application_id' => $jobApplicationId,
            'faculty_id' => $faculty->id,
        ])->assertStatus(422);

        // The earlier internship assignment is completely unaffected.
        $this->assertDatabaseHas('internship_assignments', ['application_id' => $assignedApplicationId, 'status' => 'ASSIGNED']);
    }
}
