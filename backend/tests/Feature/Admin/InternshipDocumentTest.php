<?php

namespace Tests\Feature\Admin;

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/admin/internship-documents[...] - see
 * Api\Admin\InternshipDocumentController. There is deliberately no
 * admin create/resubmit/review test here - the admin controller has no
 * write action at all (see its own docblock and the module spec's "Do
 * NOT give admin an unrestricted ability to alter student-owned
 * documents").
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

    private function makeSuperAdmin(): User
    {
        return $this->makeUser(RoleName::SUPER_ADMIN);
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

    private function documentFixture(): array
    {
        $fixture = $this->activatedFixture();

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

    // --- list / filter / view / download ---------------------------------------

    public function test_admin_can_list_all_documents(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/internship-documents');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $matching = $this->getJson('/api/admin/internship-documents?status=SUBMITTED');
        $nonMatching = $this->getJson('/api/admin/internship-documents?status=APPROVED');

        $matching->assertOk()->assertJsonPath('data.meta.total', 1);
        $nonMatching->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_admin_can_filter_by_document_type(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $matching = $this->getJson('/api/admin/internship-documents?document_type=OFFER_LETTER');
        $nonMatching = $this->getJson('/api/admin/internship-documents?document_type=FINAL_REPORT');

        $matching->assertOk()->assertJsonPath('data.meta.total', 1);
        $nonMatching->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_admin_can_filter_by_faculty(): void
    {
        $fixture = $this->documentFixture();
        $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/internship-documents?faculty_id={$fixture['faculty']->id}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_view_document_detail(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['document_id']);
        $response->assertJsonStructure(['data' => [
            'student' => ['id', 'name', 'roll_number', 'department'],
            'faculty' => ['id', 'name'],
            'internship',
            'uploaded_by',
            'history',
        ]]);
        $data = $response->json('data');
        $this->assertArrayNotHasKey('stored_filename', $data);
        $this->assertArrayNotHasKey('storage_path', $data);
    }

    public function test_admin_can_download_document(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->get("/api/admin/internship-documents/{$fixture['document_id']}/download");

        $response->assertOk();
        $response->assertHeader('content-disposition');
    }

    public function test_super_admin_access_works(): void
    {
        $fixture = $this->documentFixture();
        $superAdmin = $this->makeSuperAdmin();

        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/admin/internship-documents')->assertOk();
        $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}")->assertOk();
        $this->get("/api/admin/internship-documents/{$fixture['document_id']}/download")->assertOk();
    }

    // --- admin cannot bypass ownership rules ------------------------------------

    public function test_admin_cannot_review_documents(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->patchJson("/api/faculty/internship-documents/{$fixture['document_id']}/status", ['status' => 'UNDER_REVIEW'])->assertStatus(403);
    }

    public function test_admin_cannot_upload_or_resubmit_documents(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->post('/api/student/internship-documents', [
            'internship_assignment_id' => $fixture['assignment_id'],
            'document_type' => 'OFFER_LETTER',
            'title' => 'Admin upload',
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(403);

        $this->post("/api/student/internship-documents/{$fixture['document_id']}/resubmit", [
            'file' => UploadedFile::fake()->create('doc2.pdf', 100, 'application/pdf'),
        ])->assertStatus(403);
    }

    // --- security -------------------------------------------------------------

    public function test_student_cannot_access_admin_document_routes(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $this->getJson('/api/admin/internship-documents')->assertStatus(403);
        $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}")->assertStatus(403);
    }

    public function test_faculty_cannot_access_admin_document_routes(): void
    {
        $fixture = $this->documentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->getJson('/api/admin/internship-documents')->assertStatus(403);
        $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}")->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_document_routes(): void
    {
        $fixture = $this->documentFixture();

        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/admin/internship-documents')->assertStatus(401);
        $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}")->assertStatus(401);
        $this->getJson("/api/admin/internship-documents/{$fixture['document_id']}/download")->assertStatus(401);
    }
}
