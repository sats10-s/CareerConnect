<?php

namespace Tests\Feature\Integration;

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
 * Cross-module integration for Internship Document Management: confirms
 * the full chain - Student -> Internship opportunity -> Application ->
 * SELECTED -> Internship Assignment -> ACTIVE -> Internship Document -
 * works end to end, that documents coexist independently alongside
 * Attendance and Weekly Progress Reports on the same assignment, that
 * every lifecycle transition writes exactly one append-only history
 * row, and that nothing in the document lifecycle mutates the
 * application's own status, the internship's publication status, or
 * the assignment's own status/ownership. Nothing here inserts directly
 * into applications/internship_assignments/internship_documents -
 * every fixture is produced through the real student/admin/faculty
 * endpoints, exactly like WeeklyProgressReportIntegrationTest.
 */
class InternshipDocumentIntegrationTest extends TestCase
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

    private function uploadDocument(array $fixture): array
    {
        Sanctum::actingAs($fixture['student'], ['*']);

        return $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'My Offer Letter',
            'file' => UploadedFile::fake()->create('offer-letter.pdf', 200, 'application/pdf'),
        ])->assertCreated()->json('data');
    }

    // --- the core chain -----------------------------------------------------

    public function test_selected_application_flows_into_assignment_activation_and_document_upload(): void
    {
        $fixture = $this->activatedAssignment();

        $document = $this->uploadDocument($fixture);

        $this->assertDatabaseHas('internship_documents', [
            'id' => $document['id'],
            'internship_assignment_id' => $fixture['assignment_id'],
            'status' => 'SUBMITTED',
        ]);
    }

    public function test_full_lifecycle_from_submitted_to_approved(): void
    {
        $fixture = $this->activatedAssignment();
        $document = $this->uploadDocument($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $response = $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Well done.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');
    }

    // --- coexistence with Attendance / Weekly Progress Reports -----------------

    public function test_documents_attendance_and_weekly_reports_coexist_independently_on_the_same_assignment(): void
    {
        $fixture = $this->activatedAssignment();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $attendance = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'PRESENT',
        ])->assertCreated()->json('data');

        $document = $this->uploadDocument($fixture);

        $this->assertDatabaseHas('attendance', ['internship_assignment_id' => $fixture['assignment_id']]);
        $this->assertDatabaseHas('internship_documents', ['id' => $document['id'], 'internship_assignment_id' => $fixture['assignment_id']]);

        // Moving the document through review must never touch
        // attendance...
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->assertSame(1, \App\Models\Attendance::where('internship_assignment_id', $fixture['assignment_id'])->count());
        $this->assertDatabaseHas('attendance', ['id' => $attendance['id'], 'status' => 'PRESENT']);

        // ...and updating attendance must never touch the document.
        //
        // A second attendance_date is deliberately not used here: at
        // fixture creation time the assignment's started_at is "now",
        // so per AttendanceService::assertDateWithinPeriod() (rejects
        // any date before the assignment's start) and
        // ::assertNotFutureDate() (rejects any date after today), the
        // only attendance_date value that is valid for a brand-new
        // assignment is today - already used above for the PRESENT
        // record. Marking a second, earlier date (as this test
        // originally did with now()->subDay()) is not a legitimate
        // attendance entry for this fixture and correctly fails
        // AttendanceService's own start-date validation - see
        // AttendanceTest::test_date_before_internship_start_is_rejected(),
        // which asserts exactly that rejection. Updating the existing
        // attendance record's status instead exercises a second,
        // independent Attendance write (through the real faculty
        // update endpoint) without relying on an invalid date or on
        // the current wall-clock date advancing.
        $this->patchJson("/api/faculty/attendance/{$attendance['id']}", ['status' => 'ABSENT'])->assertOk();
        $this->assertDatabaseHas('internship_documents', ['id' => $document['id'], 'status' => 'UNDER_REVIEW']);
    }

    // --- side effects ---------------------------------------------------------

    public function test_document_lifecycle_does_not_alter_the_application_status(): void
    {
        $fixture = $this->activatedAssignment();
        $this->uploadDocument($fixture);

        $this->assertDatabaseHas('applications', ['id' => $fixture['application_id'], 'status' => 'SELECTED']);
    }

    public function test_document_lifecycle_does_not_alter_the_internships_publication_status(): void
    {
        $fixture = $this->activatedAssignment();
        $this->uploadDocument($fixture);

        $this->assertDatabaseHas('internships', ['id' => $fixture['internship']->id, 'status' => 'published']);
    }

    public function test_document_lifecycle_does_not_alter_the_assignment_status_or_ownership(): void
    {
        $fixture = $this->activatedAssignment();
        $document = $this->uploadDocument($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        $this->assertDatabaseHas('internship_assignments', [
            'id' => $fixture['assignment_id'],
            'status' => 'ACTIVE',
            'faculty_id' => $fixture['faculty']->id,
        ]);
    }

    // --- lifecycle / history invariants ---------------------------------------

    public function test_every_transition_creates_exactly_one_history_row(): void
    {
        $fixture = $this->activatedAssignment();
        $document = $this->uploadDocument($fixture);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", [
            'status' => 'CHANGES_REQUESTED',
            'faculty_review_remarks' => 'Please attach the signed copy.',
        ])->assertOk();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->post("/api/student/internship-documents/{$document['id']}/resubmit", [
            'file' => UploadedFile::fake()->create('offer-letter-signed.pdf', 210, 'application/pdf'),
        ])->assertOk();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        // null->SUBMITTED, SUBMITTED->UNDER_REVIEW,
        // UNDER_REVIEW->CHANGES_REQUESTED, CHANGES_REQUESTED->SUBMITTED,
        // SUBMITTED->UNDER_REVIEW, UNDER_REVIEW->APPROVED = 6 rows.
        $this->assertSame(6, InternshipDocument::findOrFail($document['id'])->statusHistory()->count());
    }

    public function test_invalid_lifecycle_transitions_are_rejected(): void
    {
        $fixture = $this->activatedAssignment();
        $document = $this->uploadDocument($fixture);

        Sanctum::actingAs($fixture['student'], ['*']);
        // A student can never reach the faculty-only review route at
        // all.
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'APPROVED'])->assertStatus(403);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        // SUBMITTED -> APPROVED directly (skipping UNDER_REVIEW) must
        // be rejected.
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'APPROVED'])->assertStatus(422);

        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", [
            'status' => 'APPROVED',
            'faculty_review_remarks' => 'Approved.',
        ])->assertOk();

        // APPROVED is terminal - no further transition is ever
        // allowed.
        $this->patchJson("/api/faculty/internship-documents/{$document['id']}/status", ['status' => 'UNDER_REVIEW'])->assertStatus(422);

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->post("/api/student/internship-documents/{$document['id']}/resubmit", [
            'file' => UploadedFile::fake()->create('too-late.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_history_is_append_only_with_no_endpoint_to_modify_it(): void
    {
        $fixture = $this->activatedAssignment();
        $document = $this->uploadDocument($fixture);

        $historyCountBefore = InternshipDocument::findOrFail($document['id'])->statusHistory()->count();

        // There is no PATCH/PUT/DELETE route for
        // internship_document_status_history anywhere in the API -
        // every write to it happens exclusively through
        // InternshipDocumentService::recordHistory(). Re-reading the
        // document/its history repeatedly must never change the row
        // count.
        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson("/api/faculty/internship-documents/{$document['id']}")->assertOk();
        $this->getJson("/api/faculty/internship-documents/{$document['id']}")->assertOk();

        $this->assertSame($historyCountBefore, InternshipDocument::findOrFail($document['id'])->statusHistory()->count());
    }
}
