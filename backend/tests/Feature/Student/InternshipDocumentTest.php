<?php

namespace Tests\Feature\Student;

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
use App\Services\InternshipDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Throwable;

/**
 * /api/student/internship-documents[...] - see
 * Api\Student\InternshipDocumentController and
 * App\Services\InternshipDocumentService. Also covers the upload/
 * resubmit business rules (file type/size validation, private storage,
 * orphan-free replacement) since they are all exercised through these
 * endpoints.
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

    private function upload(User $studentUser, int $assignmentId, array $overrides = []): array
    {
        Sanctum::actingAs($studentUser, ['*']);

        $file = $overrides['file'] ?? UploadedFile::fake()->create('offer-letter.pdf', 200, 'application/pdf');
        unset($overrides['file']);

        return $this->post('/api/student/internship-documents', array_merge([
            'internship_assignment_id' => $assignmentId,
            'document_type' => 'OFFER_LETTER',
            'title' => 'My Offer Letter',
            'student_notes' => 'Signed copy attached.',
            'file' => $file,
        ], $overrides))->assertCreated()->json('data');
    }

    private function documentFixture(): array
    {
        $fixture = $this->activatedFixture();
        $document = $this->upload($fixture['student'], $fixture['assignment_id']);
        $fixture['document_id'] = $document['id'];

        return $fixture;
    }

    private function changesRequestedFixture(): array
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Please attach a scanned, signed copy.',
        ])->assertOk();

        return $fixture;
    }

    private function approvedFixture(): array
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Looks good.',
        ])->assertOk();

        return $fixture;
    }

    // --- upload --------------------------------------------------------------

    public function test_student_can_upload_document(): void
    {
        $fixture = $this->activatedFixture();

        $document = $this->upload($fixture['student'], $fixture['assignment_id']);

        $this->assertSame('SUBMITTED', $document['status']);
        $this->assertDatabaseHas('internship_documents', [
            'id' => $document['id'],
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'status' => 'SUBMITTED',
        ]);
    }

    public function test_upload_metadata_is_stored(): void
    {
        $fixture = $this->activatedFixture();

        $document = $this->upload($fixture['student'], $fixture['assignment_id'], [
            'file' => UploadedFile::fake()->create('offer-letter.pdf', 250, 'application/pdf'),
        ]);

        $this->assertSame('offer-letter.pdf', $document['original_filename']);
        $this->assertSame(250 * 1024, $document['file_size']);
        $this->assertSame('My Offer Letter', $document['title']);
        $this->assertSame('Signed copy attached.', $document['student_notes']);
    }

    public function test_file_is_stored_privately(): void
    {
        $fixture = $this->activatedFixture();
        $this->upload($fixture['student'], $fixture['assignment_id']);

        $document = InternshipDocument::where('internship_assignment_id', $fixture['assignment_id'])->firstOrFail();

        // Stored under storage/app/private (the `local` disk - see
        // config/filesystems.php), never the `public` disk, and the
        // on-disk name must NOT be derived from the client-supplied
        // original filename.
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertStringNotContainsString('offer-letter', $document->stored_filename);
        $this->assertStringStartsWith("documents/{$fixture['assignment_id']}/", $document->storage_path);
    }

    public function test_upload_rejects_disallowed_file_types(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Malware',
            'file' => UploadedFile::fake()->create('payload.exe', 100, 'application/x-msdownload'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
        $this->assertDatabaseMissing('internship_documents', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_upload_rejects_files_larger_than_5mb(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Big file',
            // 5121 KB is just over the 5MB (5120 KB) limit.
            'file' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_upload_requires_document_type_and_title(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->postJson('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['document_type', 'title', 'file']);
    }

    public function test_upload_rejects_invalid_document_type(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'NOT_A_REAL_TYPE',
            'title' => 'Something',
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['document_type']);
    }

    public function test_student_cannot_set_status_manually_on_upload(): void
    {
        $fixture = $this->activatedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Sneaky',
            'status' => 'APPROVED',
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'SUBMITTED');
    }

    public function test_malicious_original_filename_cannot_escape_the_storage_directory(): void
    {
        // Path traversal attempt via the client-supplied filename - the
        // stored path must be built ONLY from the assignment id and a
        // random generated name, never from this value.
        $fixture = $this->activatedFixture();

        $document = $this->upload($fixture['student'], $fixture['assignment_id'], [
            'file' => UploadedFile::fake()->create('../../../../etc/passwd.pdf', 50, 'application/pdf'),
        ]);

        $stored = InternshipDocument::findOrFail($document['id']);
        $this->assertStringStartsWith("documents/{$fixture['assignment_id']}/", $stored->storage_path);
        $this->assertStringNotContainsString('..', $stored->storage_path);
        Storage::disk('local')->assertExists($stored->storage_path);
    }

    // --- list / view / download -----------------------------------------------

    public function test_student_can_list_own_documents(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/internship-documents');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_student_can_view_own_document(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/internship-documents/{$fixture['document_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['document_id']);
        // Student-facing detail never exposes internal storage
        // identifiers or the faculty/student/history block.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('stored_filename', $data);
        $this->assertArrayNotHasKey('storage_path', $data);
        $this->assertArrayNotHasKey('history', $data);
        $this->assertArrayNotHasKey('faculty', $data);
    }

    public function test_student_can_download_own_document(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->get("/api/student/internship-documents/{$fixture['document_id']}/download");

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('offer-letter.pdf', $response->headers->get('content-disposition'));
    }

    public function test_downloading_a_document_never_exposes_the_raw_storage_path(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $listResponse = $this->getJson('/api/student/internship-documents');
        $showResponse = $this->getJson("/api/student/internship-documents/{$fixture['document_id']}");

        $listResponse->assertOk();
        $showResponse->assertOk();
        $this->assertStringNotContainsString('storage_path', $listResponse->getContent());
        $this->assertStringNotContainsString('stored_filename', $listResponse->getContent());
        $this->assertStringNotContainsString('storage_path', $showResponse->getContent());
        $this->assertStringNotContainsString('stored_filename', $showResponse->getContent());
    }

    public function test_downloading_a_document_whose_file_is_missing_returns_a_controlled_404(): void
    {
        $fixture = $this->documentFixture();
        $document = InternshipDocument::findOrFail($fixture['document_id']);
        Storage::disk('local')->delete($document->storage_path);

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson("/api/student/internship-documents/{$fixture['document_id']}/download");

        $response->assertStatus(404);
    }

    // --- authorization / IDOR -------------------------------------------------

    public function test_student_cannot_access_another_students_document(): void
    {
        $fixture = $this->documentFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student@careerconnect.edu']);

        Sanctum::actingAs($intruder, ['*']);
        $this->getJson("/api/student/internship-documents/{$fixture['document_id']}")->assertStatus(403);
    }

    public function test_student_cannot_download_another_students_document(): void
    {
        $fixture = $this->documentFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student2@careerconnect.edu']);

        Sanctum::actingAs($intruder, ['*']);
        $this->getJson("/api/student/internship-documents/{$fixture['document_id']}/download")->assertStatus(403);
    }

    public function test_student_list_never_includes_another_students_documents(): void
    {
        $fixture = $this->documentFixture();
        $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->getJson('/api/student/internship-documents');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.items.0.id', $fixture['document_id']);
    }

    public function test_student_cannot_upload_to_another_students_assignment(): void
    {
        $fixture = $this->activatedFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student3@careerconnect.edu']);

        Sanctum::actingAs($intruder, ['*']);
        $response = $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Not mine',
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('internship_documents', ['internship_assignment_id' => $fixture['assignment_id']]);
    }

    public function test_student_cannot_resubmit_another_students_document(): void
    {
        $fixture = $this->changesRequestedFixture();
        $intruder = $this->makeStudentUser(['email' => 'intruder-student4@careerconnect.edu']);

        Sanctum::actingAs($intruder, ['*']);
        $response = $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'file' => UploadedFile::fake()->create('hijack.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(403);
    }

    public function test_student_cannot_directly_review_own_document(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'UNDER_REVIEW'])->assertStatus(403);
    }

    public function test_student_cannot_access_admin_document_routes(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson('/api/admin/internship-documents')->assertStatus(403);
        $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}")->assertStatus(403);
    }

    // --- resubmit --------------------------------------------------------------

    public function test_student_cannot_modify_approved_document(): void
    {
        $fixture = $this->approvedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'file' => UploadedFile::fake()->create('too-late.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('internship_documents', ['id' => $fixture['document_id'], 'status' => 'APPROVED']);
    }

    public function test_student_cannot_resubmit_a_freshly_submitted_document(): void
    {
        // Resubmission is only allowed once CHANGES_REQUESTED - a plain
        // SUBMITTED (not yet reviewed) document cannot be re-uploaded
        // through this action.
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'file' => UploadedFile::fake()->create('too-early.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_student_can_resubmit_changes_requested_document(): void
    {
        $fixture = $this->changesRequestedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $response = $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'student_notes' => 'Re-uploaded a signed, scanned copy.',
            'file' => UploadedFile::fake()->create('offer-letter-signed.pdf', 220, 'application/pdf'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUBMITTED');
        $response->assertJsonPath('data.original_filename', 'offer-letter-signed.pdf');
        $response->assertJsonPath('data.student_notes', 'Re-uploaded a signed, scanned copy.');
    }

    public function test_resubmission_creates_correct_status_and_history(): void
    {
        $fixture = $this->changesRequestedFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'file' => UploadedFile::fake()->create('offer-letter-signed.pdf', 220, 'application/pdf'),
        ])->assertOk();

        $this->assertDatabaseHas('internship_document_status_history', [
            'internship_document_id' => $fixture['document_id'],
            'from_status' => 'CHANGES_REQUESTED',
            'to_status' => 'SUBMITTED',
        ]);
        // null->SUBMITTED (upload), SUBMITTED->UNDER_REVIEW,
        // UNDER_REVIEW->CHANGES_REQUESTED, CHANGES_REQUESTED->SUBMITTED
        // (this resubmission) = 4 rows.
        $this->assertSame(4, InternshipDocument::findOrFail($fixture['document_id'])->statusHistory()->count());
    }

    public function test_old_file_is_replaced_only_after_successful_new_upload(): void
    {
        $fixture = $this->changesRequestedFixture();
        $originalPath = InternshipDocument::findOrFail($fixture['document_id'])->storage_path;
        Storage::disk('local')->assertExists($originalPath);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'file' => UploadedFile::fake()->create('offer-letter-signed.pdf', 220, 'application/pdf'),
        ])->assertOk();

        $newPath = InternshipDocument::findOrFail($fixture['document_id'])->storage_path;

        $this->assertNotEquals($originalPath, $newPath);
        Storage::disk('local')->assertExists($newPath);
        // No orphan left behind from the replaced upload.
        Storage::disk('local')->assertMissing($originalPath);
    }

    /**
     * FILE REPLACEMENT ORDERING (module spec section 5): if the DB
     * write fails AFTER the new file has already been stored, the new
     * file must be cleaned up (never orphaned) and the OLD file/DB row
     * must be left completely untouched. Forced here by pointing the
     * fetched model at a database connection that does not exist,
     * which makes Eloquent's update() throw deterministically without
     * needing to mock any internals - InternshipDocumentService::resubmit()
     * still runs its real code end to end up to that point.
     */
    public function test_old_file_is_retained_if_the_new_upload_fails(): void
    {
        $fixture = $this->changesRequestedFixture();
        $document = InternshipDocument::findOrFail($fixture['document_id']);
        $originalPath = $document->storage_path;
        Storage::disk('local')->assertExists($originalPath);

        $document->setConnection('a-connection-that-does-not-exist');

        $service = app(InternshipDocumentService::class);
        $newFile = UploadedFile::fake()->create('will-fail.pdf', 150, 'application/pdf');

        $threw = false;
        try {
            $service->resubmit($document, $fixture['student'], $newFile, []);
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the simulated DB failure to propagate.');

        // The old file must still be there, and there must be no
        // orphaned new file left behind in the assignment's directory.
        Storage::disk('local')->assertExists($originalPath);
        $this->assertSame(
            [$originalPath],
            Storage::disk('local')->allFiles("documents/{$fixture['assignment_id']}"),
        );

        // The DB row itself (read back through a fresh, correctly-
        // connected model) must be completely untouched.
        $reloaded = InternshipDocument::findOrFail($fixture['document_id']);
        $this->assertSame('CHANGES_REQUESTED', $reloaded->status->value);
        $this->assertSame($originalPath, $reloaded->storage_path);
    }

    // --- security ---------------------------------------------------------

    public function test_guest_cannot_access_student_document_routes(): void
    {
        $fixture = $this->changesRequestedFixture();

        // changesRequestedFixture() ends with the auth guard
        // authenticated as the faculty user (it drives real admin/
        // faculty endpoints to build the fixture) - the guard must be
        // explicitly reset for the one test here that wants a
        // genuinely logged-out request.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/student/internship-documents')->assertStatus(401);
        $this->getJson("/api/student/internship-documents/{$fixture['document_id']}")->assertStatus(401);
        $this->getJson("/api/student/internship-documents/{$fixture['document_id']}/download")->assertStatus(401);
        $this->post('/api/student/internship-documents', [])->assertStatus(401);
        $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [])->assertStatus(401);
    }
}
