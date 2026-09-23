<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Department;
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
 * POST/GET/DELETE /api/student/profile/resume.
 * See App\Http\Controllers\Api\Student\ResumeController - this is the
 * security-critical part of the Student Profile module (section 4 of
 * the spec): random on-disk filenames, storage outside the public web
 * directory, authenticated download, and orphan-free replacement.
 */
class StudentResumeTest extends TestCase
{
    use RefreshDatabase;

    private Role $studentRole;

    private Department $department;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->studentRole = Role::where('name', RoleName::STUDENT->value)->firstOrFail();
        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);

        Storage::fake('local');
    }

    private function makeStudent(array $overrides = []): User
    {
        $user = User::create([
            'name' => $overrides['name'] ?? 'Aarav Mehta',
            'email' => $overrides['email'] ?? 'aarav@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);

        Student::create(array_merge([
            'user_id' => $user->id,
            'roll_number' => $overrides['roll_number'] ?? 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
        ], $overrides));

        return $user;
    }

    public function test_student_can_upload_a_pdf_resume(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->create('my-resume.pdf', 200, 'application/pdf');

        $response = $this->post('/api/student/profile/resume', ['resume' =>$file]);

        $response->assertOk();
        $response->assertJsonPath('data.resume.original_name', 'my-resume.pdf');
        $response->assertJsonPath('data.resume.size', 200 * 1024);

        $user->student->refresh();
        $this->assertNotNull($user->student->resume_path);
        // The stored filename must NOT be the original filename - it's a
        // random, generated name (see ResumeController::store).
        $this->assertStringNotContainsString('my-resume', $user->student->resume_path);
        Storage::disk('local')->assertExists($user->student->resume_path);
    }

    public function test_student_can_upload_doc_and_docx_resumes(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $doc = UploadedFile::fake()->create('resume.doc', 150, 'application/msword');
        $this->post('/api/student/profile/resume', ['resume' =>$doc])->assertOk();

        $docx = UploadedFile::fake()->create(
            'resume.docx',
            150,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
        $this->post('/api/student/profile/resume', ['resume' =>$docx])->assertOk();
    }

    public function test_resume_upload_rejects_disallowed_file_types(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->create('resume.exe', 100, 'application/x-msdownload');

        $response = $this->post('/api/student/profile/resume', ['resume' =>$file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['resume']);

        $user->student->refresh();
        $this->assertNull($user->student->resume_path);
    }

    public function test_resume_upload_rejects_files_larger_than_5mb(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        // 5120 KB is the 5MB limit - 5121 KB must be rejected.
        $file = UploadedFile::fake()->create('big-resume.pdf', 5121, 'application/pdf');

        $response = $this->post('/api/student/profile/resume', ['resume' =>$file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['resume']);
    }

    public function test_resume_upload_requires_a_file(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/resume', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resume']);
    }

    public function test_student_can_download_own_resume(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->create('my-resume.pdf', 100, 'application/pdf');
        $this->post('/api/student/profile/resume', ['resume' =>$file])->assertOk();

        $response = $this->get('/api/student/profile/resume');

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('my-resume.pdf', $response->headers->get('content-disposition'));
    }

    public function test_downloading_resume_when_none_uploaded_returns_not_found(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->get('/api/student/profile/resume')->assertStatus(404);
    }

    public function test_student_can_delete_own_resume(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->create('my-resume.pdf', 100, 'application/pdf');
        $this->post('/api/student/profile/resume', ['resume' =>$file])->assertOk();

        $user->student->refresh();
        $storedPath = $user->student->resume_path;

        $response = $this->deleteJson('/api/student/profile/resume');

        $response->assertOk();
        $response->assertJsonPath('data.resume', null);

        $user->student->refresh();
        $this->assertNull($user->student->resume_path);
        Storage::disk('local')->assertMissing($storedPath);
    }

    /**
     * Replacing an existing resume must store + confirm the new file
     * first, then remove the old one only after the DB row points at
     * the new one - never leaving both, and never leaving neither.
     */
    public function test_replacing_a_resume_stores_the_new_file_and_removes_the_old_one(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $first = UploadedFile::fake()->create('first.pdf', 100, 'application/pdf');
        $this->post('/api/student/profile/resume', ['resume' =>$first])->assertOk();
        $user->student->refresh();
        $firstPath = $user->student->resume_path;
        Storage::disk('local')->assertExists($firstPath);

        $second = UploadedFile::fake()->create('second.pdf', 120, 'application/pdf');
        $response = $this->post('/api/student/profile/resume', ['resume' =>$second]);
        $response->assertOk();
        $response->assertJsonPath('data.resume.original_name', 'second.pdf');

        $user->student->refresh();
        $secondPath = $user->student->resume_path;

        $this->assertNotEquals($firstPath, $secondPath);
        Storage::disk('local')->assertExists($secondPath);
        // No orphan left behind from the replaced upload.
        Storage::disk('local')->assertMissing($firstPath);
    }

    /**
     * IDOR: there is no {id} in the resume routes - every operation is
     * always scoped to $request->user()->student. This proves that two
     * different authenticated students each only ever see/replace/delete
     * their OWN resume from the exact same endpoints, never each other's.
     */
    public function test_student_cannot_access_another_students_resume(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent(['email' => 'diya@careerconnect.edu', 'roll_number' => 'CC24002']);

        Sanctum::actingAs($studentA, ['*']);
        $aFile = UploadedFile::fake()->create('aarav-resume.pdf', 100, 'application/pdf');
        $this->post('/api/student/profile/resume', ['resume' =>$aFile])->assertOk();

        Sanctum::actingAs($studentB, ['*']);

        // Student B has never uploaded a resume - the "same" download
        // endpoint must 404 for them, never stream Student A's file.
        $this->get('/api/student/profile/resume')->assertStatus(404);

        $bFile = UploadedFile::fake()->create('diya-resume.pdf', 100, 'application/pdf');
        $this->post('/api/student/profile/resume', ['resume' =>$bFile])->assertOk();

        // Student B deleting "their" resume must never touch Student A's.
        $this->deleteJson('/api/student/profile/resume')->assertOk();

        $studentA->student->refresh();
        $this->assertNotNull($studentA->student->resume_path);
        Storage::disk('local')->assertExists($studentA->student->resume_path);
    }

    public function test_guest_cannot_manage_resume(): void
    {
        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->post('/api/student/profile/resume', ['resume' =>$file])->assertStatus(401);
        $this->get('/api/student/profile/resume')->assertStatus(401);
        $this->deleteJson('/api/student/profile/resume')->assertStatus(401);
    }
}
