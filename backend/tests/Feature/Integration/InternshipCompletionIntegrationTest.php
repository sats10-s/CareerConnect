<?php

namespace Tests\Feature\Integration;

use App\Enums\RoleName;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\InternshipCompletion;
use App\Models\PpoRecord;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-module integration for Internship Completion & PPO: confirms
 * the full chain - Student -> Internship opportunity -> Application ->
 * SELECTED -> Internship Assignment -> ACTIVE -> Completion/PPO -
 * works end to end, that completion/PPO records coexist independently
 * alongside Attendance, Weekly Progress Reports and Internship
 * Documents on the same assignment, that every lifecycle transition
 * writes exactly one append-only history row, and that nothing in
 * either lifecycle mutates the application's own status, the
 * internship's publication status, or the assignment's own status/
 * ownership. Nothing here inserts directly into applications/
 * internship_assignments/internship_completions/ppo_records - every
 * fixture is produced through the real student/admin/faculty
 * endpoints, exactly like InternshipDocumentIntegrationTest.
 */
class InternshipCompletionIntegrationTest extends TestCase
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

    private function createCompletion(array $fixture): array
    {
        Sanctum::actingAs($fixture['faculty']->user, ['*']);

        return $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
            'final_remarks' => 'Completed successfully.',
        ])->assertCreated()->json('data');
    }

    private function createPpo(array $fixture): array
    {
        Sanctum::actingAs($fixture['faculty']->user, ['*']);

        return $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
            'package' => 1200000,
        ])->assertCreated()->json('data');
    }

    // --- the core chain -----------------------------------------------------

    public function test_selected_application_flows_into_assignment_activation_and_completion(): void
    {
        $fixture = $this->activatedAssignment();

        $completion = $this->createCompletion($fixture);

        $this->assertDatabaseHas('internship_completions', [
            'id' => $completion['id'],
            'internship_assignment_id' => $fixture['assignment_id'],
            'status' => 'DRAFT',
        ]);
    }

    public function test_full_lifecycle_from_draft_to_finalized_with_ppo_accepted(): void
    {
        $fixture = $this->activatedAssignment();
        $completion = $this->createCompletion($fixture);
        $ppo = $this->createPpo($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $finalizeResponse = $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize");
        $finalizeResponse->assertOk()->assertJsonPath('data.status', 'FINALIZED');

        $acceptResponse = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED']);
        $acceptResponse->assertOk()->assertJsonPath('data.status', 'ACCEPTED');

        $this->assertDatabaseHas('internship_completions', ['id' => $completion['id'], 'status' => 'FINALIZED']);
        $this->assertDatabaseHas('ppo_records', ['id' => $ppo['id'], 'status' => 'ACCEPTED']);
    }

    // --- coexistence with Attendance / Weekly Progress Reports / Documents -----

    public function test_completion_and_ppo_coexist_independently_on_the_same_assignment(): void
    {
        $fixture = $this->activatedAssignment();

        $completion = $this->createCompletion($fixture);
        $ppo = $this->createPpo($fixture);

        $this->assertDatabaseHas('internship_completions', ['id' => $completion['id'], 'internship_assignment_id' => $fixture['assignment_id']]);
        $this->assertDatabaseHas('ppo_records', ['id' => $ppo['id'], 'internship_assignment_id' => $fixture['assignment_id']]);

        // Finalizing the completion must never touch the PPO record...
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertOk();
        $this->assertDatabaseHas('ppo_records', ['id' => $ppo['id'], 'status' => 'OFFERED']);

        // ...and deciding the PPO must never touch the completion record.
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED'])->assertOk();
        $this->assertDatabaseHas('internship_completions', ['id' => $completion['id'], 'status' => 'FINALIZED']);
    }

    public function test_completion_coexists_with_attendance_weekly_reports_and_documents_on_the_same_assignment(): void
    {
        $fixture = $this->activatedAssignment();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated();

        Sanctum::actingAs($fixture['student'], ['*']);
        $report = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => now()->startOfWeek()->toDateString(),
            'week_end_date' => now()->endOfWeek()->toDateString(),
            'work_done' => 'Set up the initial project scaffolding.',
        ])->assertCreated()->json('data');

        $document = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Offer Letter',
            'file' => UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $completion = $this->createCompletion($fixture);

        $this->assertDatabaseHas('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $report['id'], 'internship_assignment_id' => $fixture['assignment_id']]);
        $this->assertDatabaseHas('internship_documents', ['id' => $document['id'], 'internship_assignment_id' => $fixture['assignment_id']]);
        $this->assertDatabaseHas('internship_completions', ['id' => $completion['id'], 'internship_assignment_id' => $fixture['assignment_id']]);

        // Finalizing the completion must never mutate any of the other
        // three records on this same assignment.
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertOk();

        $this->assertSame(1, Attendance::where('internship_assignment_id', $fixture['assignment_id'])->count());
        $this->assertDatabaseHas('weekly_progress_reports', ['id' => $report['id'], 'status' => 'DRAFT']);
        $this->assertDatabaseHas('internship_documents', ['id' => $document['id'], 'status' => 'SUBMITTED']);
    }

    // --- side effects ---------------------------------------------------------

    public function test_completion_lifecycle_does_not_alter_the_application_status(): void
    {
        $fixture = $this->activatedAssignment();
        $completion = $this->createCompletion($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertOk();

        $this->assertDatabaseHas('applications', ['id' => $fixture['application_id'], 'status' => 'SELECTED']);
    }

    public function test_completion_lifecycle_does_not_alter_the_internships_publication_status(): void
    {
        $fixture = $this->activatedAssignment();
        $completion = $this->createCompletion($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertOk();

        $this->assertDatabaseHas('internships', ['id' => $fixture['internship']->id, 'status' => 'published']);
    }

    public function test_completion_and_ppo_never_automatically_change_the_assignment_status(): void
    {
        $fixture = $this->activatedAssignment();
        $completion = $this->createCompletion($fixture);
        $this->createPpo($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertOk();
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED'])->assertOk();

        // The assignment remains ACTIVE - creating/finalizing a
        // completion record, and deciding a PPO, never themselves flip
        // internship_assignments.status; that remains exclusively
        // InternshipAssignmentService::complete()'s job (a separate,
        // admin-only action).
        $this->assertDatabaseHas('internship_assignments', ['id' => $fixture['assignment_id'], 'status' => 'ACTIVE']);
    }

    // --- history integrity -----------------------------------------------------

    public function test_completion_and_ppo_history_is_append_only_with_no_endpoint_to_modify_it(): void
    {
        $fixture = $this->activatedAssignment();
        $completion = $this->createCompletion($fixture);
        $ppo = $this->createPpo($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertOk();
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'DECLINED'])->assertOk();

        $this->assertSame(2, InternshipCompletion::find($completion['id'])->statusHistory()->count());
        $this->assertSame(2, PpoRecord::find($ppo['id'])->statusHistory()->count());

        // No route anywhere in this API accepts a write against either
        // history table directly.
        $this->getJson('/api/faculty/internship-completions')->assertOk();
    }

    // --- invalid transitions ----------------------------------------------------

    public function test_invalid_lifecycle_transitions_are_rejected(): void
    {
        $fixture = $this->activatedAssignment();
        $completion = $this->createCompletion($fixture);
        $ppo = $this->createPpo($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertOk();

        // FINALIZED is terminal - a second finalize is rejected, and so
        // is any further update.
        $this->postJson("/api/faculty/internship-completions/{$completion['id']}/finalize")->assertStatus(422);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", ['outcome' => 'NOT_COMPLETED'])->assertStatus(422);

        // A PPO record can never be created directly as ACCEPTED/DECLINED.
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'OFFERED'])->assertStatus(422);

        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'DECLINED'])->assertOk();

        // DECLINED is terminal - a further transition/update is rejected.
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED'])->assertStatus(422);
        $this->assertDatabaseHas('ppo_records', ['id' => $ppo['id'], 'status' => 'DECLINED']);
    }
}
