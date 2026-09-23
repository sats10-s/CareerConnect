<?php

namespace Tests\Feature\Faculty;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\InternshipDocument;
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
 * /api/faculty/internship-documents[...] - see
 * Api\Faculty\InternshipDocumentController and
 * App\Services\InternshipDocumentService. There is no store()/
 * resubmit() here at all - a faculty member never uploads or replaces
 * a student's file, only moves it through the review lifecycle (see
 * that controller's own docblock).
 */
class InternshipDocumentTest extends TestCase
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

    private function documentFixture(array $fixture): array
    {
        Sanctum::actingAs($fixture['student'], ['*']);
        $document = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'My Offer Letter',
            'file' => UploadedFile::fake()->create('offer-letter.pdf', 200, 'application/pdf'),
        ])->assertCreated()->json('data');

        $fixture['document_id'] = $document['id'];

        return $fixture;
    }

    private function submittedFixture(): array
    {
        return $this->documentFixture($this->activatedFixture());
    }

    private function underReviewFixture(): array
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();

        return $fixture;
    }

    private function approvedFixture(): array
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        return $fixture;
    }

    // --- list / view / download -----------------------------------------------

    public function test_faculty_can_list_assigned_students_documents(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/internship-documents');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_faculty_can_view_assigned_students_document(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/internship-documents/{$fixture['document_id']}");

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number'],
            'faculty' => ['id', 'name'],
            'internship',
            'history',
        ]]);
        $data = $response->json('data');
        $this->assertArrayNotHasKey('stored_filename', $data);
        $this->assertArrayNotHasKey('storage_path', $data);
    }

    public function test_faculty_can_download_assigned_students_document(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->get("/api/faculty/internship-documents/{$fixture['document_id']}/download");

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('offer-letter.pdf', $response->headers->get('content-disposition'));
    }

    public function test_faculty_cannot_access_unrelated_students_document(): void
    {
        $fixture = $this->submittedFixture();
        $intruder = $this->makeFaculty(['email' => 'intruder-faculty@careerconnect.edu']);

        Sanctum::actingAs($intruder->user, ['*']);
        $this->getJson("/api/faculty/internship-documents/{$fixture['document_id']}")->assertStatus(403);
        $this->getJson("/api/faculty/internship-documents/{$fixture['document_id']}/download")->assertStatus(403);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'UNDER_REVIEW'])->assertStatus(403);
    }

    public function test_faculty_list_never_includes_another_facultys_documents(): void
    {
        $fixture = $this->submittedFixture();
        $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/internship-documents');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['document_id']);
    }

    public function test_student_id_filter_cannot_escape_faculty_scope(): void
    {
        $fixture = $this->submittedFixture();
        $otherOwnedByAnotherFaculty = $this->submittedFixture();
        $otherStudentId = Student::where('user_id', $otherOwnedByAnotherFaculty['student']->id)->firstOrFail()->id;

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson("/api/faculty/internship-documents?student_id={$otherStudentId}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 0);
    }

    // --- review lifecycle ------------------------------------------------------

    public function test_faculty_can_move_submitted_to_under_review(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'UNDER_REVIEW']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->assertDatabaseHas('internship_document_status_history', [
            'internship_document_id' => $fixture['document_id'],
            'from_status' => 'SUBMITTED',
            'to_status' => 'UNDER_REVIEW',
        ]);
    }

    public function test_faculty_can_approve(): void
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'All good.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');
        $this->assertNotNull($response->json('data.approved_at'));
    }

    public function test_faculty_can_request_changes(): void
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Please re-scan the signature page.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CHANGES_REQUESTED');
        $this->assertNotNull($response->json('data.changes_requested_at'));
    }

    public function test_faculty_cannot_request_changes_without_remarks(): void
    {
        $fixture = $this->underReviewFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'CHANGES_REQUESTED']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['faculty_review_remarks']);
        $this->assertDatabaseHas('internship_documents', ['id' => $fixture['document_id'], 'status' => 'UNDER_REVIEW']);
    }

    public function test_approved_document_cannot_be_changed(): void
    {
        $fixture = $this->approvedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Too late.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('internship_documents', ['id' => $fixture['document_id'], 'status' => 'APPROVED']);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        // SUBMITTED -> APPROVED directly (skipping UNDER_REVIEW) must
        // be rejected.
        $response = $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Skipping review.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('internship_documents', ['id' => $fixture['document_id'], 'status' => 'SUBMITTED']);
    }

    public function test_faculty_cannot_replace_student_files(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'file' => UploadedFile::fake()->create('faculty-upload.pdf', 100, 'application/pdf'),
        ])->assertStatus(403);
    }

    public function test_faculty_cannot_upload_a_document_for_a_student(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Faculty upload',
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(403);
    }

    public function test_faculty_cannot_directly_modify_student_notes(): void
    {
        $fixture = $this->submittedFixture();
        $originalNotes = InternshipDocument::findOrFail($fixture['document_id'])->student_notes;

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        // There is no field on the review request that could ever
        // reach student_notes - the review action only ever writes
        // status/reviewed_at/approved_at/changes_requested_at/
        // faculty_review_remarks (see
        // InternshipDocumentService::transition()'s own docblock).
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'UNDER_REVIEW',
            'student_notes' => 'Overwritten by faculty.',
        ])->assertOk();

        $this->assertSame($originalNotes, InternshipDocument::findOrFail($fixture['document_id'])->student_notes);
    }

    public function test_faculty_cannot_access_admin_document_routes(): void
    {
        $fixture = $this->submittedFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson('/api/admin/internship-documents')->assertStatus(403);
        $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}")->assertStatus(403);
    }

    // --- security -------------------------------------------------------------

    public function test_guest_cannot_access_faculty_document_routes(): void
    {
        $fixture = $this->submittedFixture();

        // submittedFixture() ends with the auth guard authenticated as
        // the student (it drives real student/admin endpoints to build
        // the fixture) - the guard must be explicitly reset for this
        // one test that wants a genuinely logged-out request.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/faculty/internship-documents')->assertStatus(401);
        $this->getJson("/api/faculty/internship-documents/{$fixture['document_id']}")->assertStatus(401);
        $this->getJson("/api/faculty/internship-documents/{$fixture['document_id']}/download")->assertStatus(401);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [])->assertStatus(401);
    }
}
