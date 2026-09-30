<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Application;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\JobPosting;
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
 * GET /api/admin/students, /api/admin/students/{student} and
 * /api/admin/students/{student}/resume - see Api\Admin\StudentController.
 */
class StudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Department $cse;

    private Department $mech;

    private Batch $batch;

    private JobPosting $job;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->cse = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->mech = Department::create(['name' => 'Mechanical Engineering', 'code' => 'MECH']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
        $company = Company::create(['name' => 'NovaTech Solutions', 'industry' => 'Software', 'is_active' => true]);
        $this->job = JobPosting::create(['company_id' => $company->id, 'title' => 'Software Engineer I', 'status' => 'published']);
    }

    private function makeUser(RoleName $role, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => strtolower($role->value).'-'.uniqid().'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => Role::where('name', $role->value)->firstOrFail()->id,
        ], $overrides));
    }

    private function makeStudent(string $name, string $roll, Department $department, array $overrides = []): Student
    {
        $user = $this->makeUser(RoleName::STUDENT, ['name' => $name]);

        return Student::create(array_merge([
            'user_id' => $user->id,
            'roll_number' => $roll,
            'department_id' => $department->id,
            'batch_id' => $this->batch->id,
            'year' => 4,
            'cgpa' => 8.0,
            'backlogs' => 0,
        ], $overrides));
    }

    private function apply(Student $student, string $status): Application
    {
        return Application::create([
            'student_id' => $student->id,
            'applicable_type' => JobPosting::class,
            'applicable_id' => $this->job->id,
            'status' => $status,
            'applied_at' => now(),
        ]);
    }

    public function test_admin_lists_students_with_counts_and_placement_status(): void
    {
        $aarav = $this->makeStudent('Aarav Mehta', 'CC24001', $this->cse);
        $this->makeStudent('Priya Nair', 'CC24002', $this->mech);
        $this->apply($aarav, 'SELECTED');

        Sanctum::actingAs($this->makeUser(RoleName::PLACEMENT_ADMIN), ['*']);
        $response = $this->getJson('/api/admin/students')->assertOk();

        $response->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.items.0.name', 'Aarav Mehta')
            ->assertJsonPath('data.items.0.applications_count', 1)
            ->assertJsonPath('data.items.0.placement_status', 'SELECTED')
            ->assertJsonPath('data.items.1.placement_status', null);
        $this->assertArrayNotHasKey('resume_path', $response->json('data.items.0'));
    }

    public function test_filters_search_department_and_placement(): void
    {
        $aarav = $this->makeStudent('Aarav Mehta', 'CC24001', $this->cse);
        $this->makeStudent('Priya Nair', 'CC24002', $this->mech);
        $this->makeStudent('Kabir Singh', 'CC24003', $this->cse, ['year' => 3]);
        $this->apply($aarav, 'SELECTED');

        Sanctum::actingAs($this->makeUser(RoleName::PLACEMENT_ADMIN), ['*']);

        $this->getJson('/api/admin/students?search=CC24002')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.name', 'Priya Nair');
        $this->getJson('/api/admin/students?search=kabir')->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/admin/students?department_id={$this->cse->id}")->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/admin/students?year=3')->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/admin/students?placement=placed')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.name', 'Aarav Mehta');
        $this->getJson('/api/admin/students?placement=unplaced')->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/admin/students?placement=bogus')->assertStatus(422);
        $this->getJson('/api/admin/students?sort=password')->assertStatus(422);
    }

    public function test_super_admin_can_list_but_faculty_and_students_cannot(): void
    {
        $this->makeStudent('Aarav Mehta', 'CC24001', $this->cse);

        Sanctum::actingAs($this->makeUser(RoleName::SUPER_ADMIN), ['*']);
        $this->getJson('/api/admin/students')->assertOk();

        $faculty = $this->makeUser(RoleName::FACULTY);
        Faculty::create(['user_id' => $faculty->id, 'employee_code' => 'FAC1001', 'department_id' => $this->cse->id]);
        Sanctum::actingAs($faculty, ['*']);
        $this->getJson('/api/admin/students')->assertForbidden();

        $student = $this->makeStudent('Priya Nair', 'CC24002', $this->mech);
        Sanctum::actingAs($student->user, ['*']);
        $this->getJson('/api/admin/students')->assertForbidden();
        $this->getJson("/api/admin/students/{$student->id}")->assertForbidden();
    }

    public function test_show_returns_profile_applications_and_assignments(): void
    {
        $aarav = $this->makeStudent('Aarav Mehta', 'CC24001', $this->cse);
        $this->apply($aarav, 'SHORTLISTED');

        Sanctum::actingAs($this->makeUser(RoleName::PLACEMENT_ADMIN), ['*']);
        $this->getJson("/api/admin/students/{$aarav->id}")
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Aarav Mehta')
            ->assertJsonPath('data.profile.roll_number', 'CC24001')
            ->assertJsonPath('data.applications.0.status', 'SHORTLISTED')
            ->assertJsonPath('data.applications.0.opportunity.title', 'Software Engineer I')
            ->assertJsonPath('data.applications.0.opportunity.company.name', 'NovaTech Solutions')
            ->assertJsonPath('data.internship_assignments', []);

        $this->getJson('/api/admin/students/999999')->assertNotFound();
    }

    public function test_resume_download_is_admin_only_and_404s_without_a_file(): void
    {
        Storage::fake('local');
        $aarav = $this->makeStudent('Aarav Mehta', 'CC24001', $this->cse);

        Sanctum::actingAs($this->makeUser(RoleName::PLACEMENT_ADMIN), ['*']);
        $this->getJson("/api/admin/students/{$aarav->id}/resume")->assertNotFound()->assertJsonPath('success', false);

        $path = UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')->storeAs('resumes/'.$aarav->id, 'stored.pdf', 'local');
        $aarav->update(['resume_path' => $path, 'resume_original_name' => 'Aarav_CV.pdf']);

        $this->get("/api/admin/students/{$aarav->id}/resume")->assertOk()->assertDownload('Aarav_CV.pdf');
    }
}
