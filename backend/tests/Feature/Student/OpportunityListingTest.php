<?php

namespace Tests\Feature\Student;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
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
 * GET /api/student/opportunities[/jobs/{jobPosting}|/internships/{internship}].
 * See App\Http\Controllers\Api\Student\OpportunityController.
 */
class OpportunityListingTest extends TestCase
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
        $this->company = Company::create([
            'name' => 'NovaTech Solutions',
            'industry' => 'Software',
            'is_active' => true,
        ]);
    }

    private function makeStudent(): User
    {
        $roleModel = Role::where('name', RoleName::STUDENT->value)->firstOrFail();

        $user = User::create([
            'name' => 'Aarav Mehta',
            'email' => 'aarav@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $roleModel->id,
        ]);

        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
        ]);

        return $user;
    }

    private function makeJobPosting(array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Software Engineer I',
            'location' => 'Bengaluru',
            'status' => 'published',
            'is_active' => true,
        ], $overrides));
    }

    private function makeInternship(array $overrides = []): Internship
    {
        return Internship::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Backend Intern',
            'location' => 'Remote',
            'duration_months' => 6,
            'status' => 'published',
            'is_active' => true,
        ], $overrides));
    }

    public function test_published_job_posting_appears_in_the_listing(): void
    {
        $this->makeJobPosting(['title' => 'Published Job']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson('/api/student/opportunities');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonFragment(['title' => 'Published Job']);
    }

    public function test_draft_job_posting_does_not_appear_in_the_listing(): void
    {
        $this->makeJobPosting(['title' => 'Draft Job', 'status' => 'draft']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson('/api/student/opportunities');

        $response->assertOk();
        $response->assertJsonMissing(['title' => 'Draft Job']);
    }

    public function test_closed_job_posting_does_not_appear_in_the_listing(): void
    {
        $this->makeJobPosting(['title' => 'Closed Job', 'status' => 'closed']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson('/api/student/opportunities');

        $response->assertOk();
        $response->assertJsonMissing(['title' => 'Closed Job']);
    }

    public function test_listing_mixes_published_jobs_and_internships(): void
    {
        $this->makeJobPosting(['title' => 'Mixed Job']);
        $this->makeInternship(['title' => 'Mixed Internship']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson('/api/student/opportunities');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Mixed Job', 'type' => 'job']);
        $response->assertJsonFragment(['title' => 'Mixed Internship', 'type' => 'internship']);
        $response->assertJsonPath('data.meta.total', 2);
    }

    public function test_student_can_view_a_single_published_job_posting(): void
    {
        $jobPosting = $this->makeJobPosting(['title' => 'Viewable Job']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Viewable Job');
    }

    public function test_student_cannot_view_an_unpublished_job_posting(): void
    {
        $jobPosting = $this->makeJobPosting(['title' => 'Hidden Job', 'status' => 'draft']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $this->getJson("/api/student/opportunities/jobs/{$jobPosting->id}")->assertStatus(403);
    }

    public function test_pagination_limits_results_per_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeJobPosting(['title' => "Job {$i}"]);
        }
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson('/api/student/opportunities?per_page=2');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 5);
        $response->assertJsonPath('data.meta.per_page', 2);
        $response->assertJsonPath('data.meta.last_page', 3);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_search_and_type_filters_narrow_the_listing(): void
    {
        $this->makeJobPosting(['title' => 'Frontend Engineer']);
        $this->makeInternship(['title' => 'Frontend Intern']);
        $this->makeJobPosting(['title' => 'Backend Engineer']);
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $response = $this->getJson('/api/student/opportunities?search=Frontend');
        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 2);

        $response = $this->getJson('/api/student/opportunities?type=internship');
        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['title' => 'Frontend Intern']);
    }
}
