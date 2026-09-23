<?php

namespace Tests\Feature\Faculty;

use App\Enums\RoleName;
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
 * POST/PATCH /api/faculty/internship-assignments/{assignment}/completion,
 * GET /api/faculty/internship-completions[...],
 * POST /api/faculty/internship-completions/{completion}/finalize - see
 * Api\Faculty\InternshipCompletionController. Also covers the mirrored
 * PPO write/read surface (POST/PATCH .../ppo, GET /api/faculty/ppo-records[...])
 * since both resources are exercised through the same
 * InternshipCompletionService and are kept in the SAME test class so a
 * single `--filter=InternshipCompletion` run covers all of it.
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

    /**
     * An ASSIGNED (never activated) fixture, for eligibility-gate tests.
     */
    private function assignedOnlyFixture(): array
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

    private function completionFixture(): array
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $completion = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
            'final_remarks' => 'Solid performance throughout.',
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

    // --- Completion: creation ---------------------------------------------

    public function test_faculty_can_create_completion_for_own_assignment(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
            'final_remarks' => 'Great intern.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.outcome', 'COMPLETED');
        $this->assertDatabaseHas('internship_completions', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'outcome' => 'COMPLETED',
            'status' => 'DRAFT',
            'completed_by' => $fixture['faculty']->user->id,
        ]);
    }

    public function test_completion_creation_requires_active_or_completed_assignment(): void
    {
        $fixture = $this->assignedOnlyFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('internship_completions', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_completion_can_be_created_after_assignment_is_marked_completed(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/internship-assignments/{$fixture['assignment_id']}/complete")->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ])->assertCreated();
    }

    public function test_duplicate_completion_creation_is_rejected(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, InternshipCompletion::where('internship_assignment_id', $fixture['assignment_id'])->count());
    }

    public function test_completion_creation_does_not_alter_assignment_or_application_status(): void
    {
        $fixture = $this->completionFixture();

        $this->assertDatabaseHas('internship_assignments', ['id' => $fixture['assignment_id'], 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('applications', ['id' => $fixture['application_id'], 'status' => 'SELECTED']);
    }

    public function test_invalid_outcome_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'PARTIALLY_DONE',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['outcome']);
    }

    public function test_future_completion_date_is_rejected(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->addDay()->toDateString(),
            'outcome' => 'COMPLETED',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['completion_date']);
    }

    // --- Completion: certificate document linking ------------------------------

    public function test_completion_certificate_document_must_belong_to_same_assignment(): void
    {
        $fixtureA = $this->activatedFixture();
        $fixtureB = $this->activatedFixture();

        Sanctum::actingAs($fixtureB['student'], ['*']);
        $otherDocument = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixtureB['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Someone elses document',
            'file' => UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        Sanctum::actingAs($fixtureA['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixtureA['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
            'completion_certificate_document_id' => $otherDocument['id'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('internship_completions', ['internship_assignment_id' => $fixtureA['assignment_id']]);
    }

    public function test_completion_certificate_document_from_same_assignment_is_accepted(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $document = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'COMPLETION_CERTIFICATE',
            'title' => 'Certificate',
            'file' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
            'completion_certificate_document_id' => $document['id'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.completion_certificate_document_id', $document['id']);
    }

    // --- Completion: update -----------------------------------------------------

    public function test_faculty_can_update_draft_completion(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'outcome' => 'NOT_COMPLETED',
            'final_remarks' => 'Revised assessment.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.outcome', 'NOT_COMPLETED');
        $this->assertDatabaseHas('internship_completions', [
            'id' => $fixture['completion_id'],
            'outcome' => 'NOT_COMPLETED',
            'final_remarks' => 'Revised assessment.',
        ]);
    }

    public function test_updating_a_draft_completion_does_not_write_history(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'final_remarks' => 'Tweaked remarks.',
        ])->assertOk();

        $this->assertSame(1, InternshipCompletion::find($fixture['completion_id'])->statusHistory()->count());
    }

    public function test_update_without_existing_completion_returns_not_found(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'final_remarks' => 'No completion yet.',
        ]);

        $response->assertStatus(404);
    }

    // --- Completion: finalize / immutability ------------------------------------

    public function test_faculty_can_finalize_completion(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-completions/{$fixture['completion_id']}/finalize");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'FINALIZED');
        $this->assertNotNull($response->json('data.finalized_at'));
        $this->assertDatabaseHas('internship_completions', ['id' => $fixture['completion_id'], 'status' => 'FINALIZED']);
    }

    public function test_finalized_completion_is_immutable(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$fixture['completion_id']}/finalize")->assertOk();

        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'final_remarks' => 'Trying to sneak in a change.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('internship_completions', [
            'id' => $fixture['completion_id'],
            'final_remarks' => 'Solid performance throughout.',
        ]);
    }

    public function test_finalizing_an_already_finalized_completion_is_rejected(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$fixture['completion_id']}/finalize")->assertOk();

        $response = $this->postJson("/api/faculty/internship-completions/{$fixture['completion_id']}/finalize");

        $response->assertStatus(422);
    }

    public function test_completion_history_records_creation_and_finalization(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-completions/{$fixture['completion_id']}/finalize")->assertOk();

        $history = InternshipCompletion::find($fixture['completion_id'])->statusHistory()->orderBy('created_at')->get();

        $this->assertSame(2, $history->count());
        $this->assertNull($history[0]->from_status);
        $this->assertSame('DRAFT', $history[0]->to_status->value);
        $this->assertSame('DRAFT', $history[1]->from_status->value);
        $this->assertSame('FINALIZED', $history[1]->to_status->value);
    }

    // --- Completion: IDOR / scoping ----------------------------------------------

    public function test_faculty_cannot_create_completion_for_unassigned_student(): void
    {
        $fixture = $this->activatedFixture();
        $otherFaculty = $this->makeFaculty();

        Sanctum::actingAs($otherFaculty->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('internship_completions', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_faculty_cannot_view_or_update_another_facultys_completion(): void
    {
        $fixture = $this->completionFixture();
        $otherFaculty = $this->makeFaculty();

        Sanctum::actingAs($otherFaculty->user, ['*']);
        $this->getJson("/api/faculty/internship-completions/{$fixture['completion_id']}")->assertStatus(403);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", ['final_remarks' => 'IDOR attempt'])->assertStatus(403);
        $this->postJson("/api/faculty/internship-completions/{$fixture['completion_id']}/finalize")->assertStatus(403);
    }

    public function test_faculty_index_only_lists_own_completions(): void
    {
        $fixture = $this->completionFixture();
        $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/internship-completions');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_student_cannot_create_or_update_completion(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/completion", [
            'completion_date' => now()->toDateString(),
            'outcome' => 'COMPLETED',
        ])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_completion_routes(): void
    {
        $fixture = $this->completionFixture();

        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/faculty/internship-completions')->assertStatus(401);
        $this->getJson("/api/faculty/internship-completions/{$fixture['completion_id']}")->assertStatus(401);
    }

    // --- PPO: creation -----------------------------------------------------------

    public function test_faculty_can_create_ppo_for_own_assignment(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
            'package' => 1500000,
            'remarks' => 'Strong performer.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'OFFERED');
        $this->assertDatabaseHas('ppo_records', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'company_id' => $this->company->id,
            'designation' => 'Software Engineer',
            'status' => 'OFFERED',
        ]);
    }

    public function test_ppo_company_is_derived_from_assignment_never_from_client(): void
    {
        $fixture = $this->activatedFixture();
        $otherCompany = Company::create(['name' => 'Spoofed Corp', 'industry' => 'Finance', 'is_active' => true]);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Analyst',
            'company_id' => $otherCompany->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ppo_records', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'company_id' => $this->company->id,
        ]);
        $this->assertDatabaseMissing('ppo_records', ['company_id' => $otherCompany->id]);
    }

    public function test_ppo_creation_requires_active_or_completed_assignment(): void
    {
        $fixture = $this->assignedOnlyFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_ppo_creation_is_rejected(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, PpoRecord::where('internship_assignment_id', $fixture['assignment_id'])->count());
    }

    public function test_ppo_is_optional_and_absent_by_default(): void
    {
        $fixture = $this->completionFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/ppo-records');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
        $this->assertDatabaseMissing('ppo_records', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    // --- PPO: update / decision --------------------------------------------------

    public function test_faculty_can_update_ppo_fields_while_offered(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'package' => 1800000,
            'joining_date' => now()->addMonths(6)->toDateString(),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('ppo_records', ['id' => $fixture['ppo_id'], 'package' => '1800000.00']);
    }

    public function test_faculty_can_accept_ppo(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'status' => 'ACCEPTED',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ACCEPTED');
        $this->assertDatabaseHas('ppo_records', ['id' => $fixture['ppo_id'], 'status' => 'ACCEPTED']);
    }

    public function test_faculty_can_decline_ppo(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'status' => 'DECLINED',
            'remarks' => 'Student chose another offer.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'DECLINED');
    }

    public function test_terminal_ppo_record_is_immutable(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED'])->assertOk();

        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'package' => 999999,
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_ppo_status_value_is_rejected(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'status' => 'OFFERED',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_ppo_history_records_creation_and_decision(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['status' => 'ACCEPTED'])->assertOk();

        $history = PpoRecord::find($fixture['ppo_id'])->statusHistory()->orderBy('created_at')->get();

        $this->assertSame(2, $history->count());
        $this->assertNull($history[0]->from_status);
        $this->assertSame('OFFERED', $history[0]->to_status->value);
        $this->assertSame('OFFERED', $history[1]->from_status->value);
        $this->assertSame('ACCEPTED', $history[1]->to_status->value);
    }

    public function test_updating_ppo_fields_without_status_does_not_write_history(): void
    {
        $fixture = $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['package' => 2000000])->assertOk();

        $this->assertSame(1, PpoRecord::find($fixture['ppo_id'])->statusHistory()->count());
    }

    // --- PPO: IDOR / scoping ------------------------------------------------------

    public function test_faculty_cannot_create_ppo_for_unassigned_student(): void
    {
        $fixture = $this->activatedFixture();
        $otherFaculty = $this->makeFaculty();

        Sanctum::actingAs($otherFaculty->user, ['*']);
        $response = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('ppo_records', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_faculty_cannot_view_or_update_another_facultys_ppo(): void
    {
        $fixture = $this->ppoFixture();
        $otherFaculty = $this->makeFaculty();

        Sanctum::actingAs($otherFaculty->user, ['*']);
        $this->getJson("/api/faculty/ppo-records/{$fixture['ppo_id']}")->assertStatus(403);
        $this->patchJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", ['package' => 1])->assertStatus(403);
    }

    public function test_faculty_index_only_lists_own_ppo_records(): void
    {
        $fixture = $this->ppoFixture();
        $this->ppoFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/ppo-records');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_student_cannot_create_or_update_ppo(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
        ])->assertStatus(403);
    }
}
