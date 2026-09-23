<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\PlacementDrive;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST/GET/PATCH /api/admin/placement-drives[...] and the publish/
 * close/cancel/attach/detach sub-actions - see
 * Api\Admin\PlacementDriveController and
 * App\Services\PlacementDriveService.
 */
class PlacementDriveTest extends TestCase
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

    private function makeFaculty(): User
    {
        $user = $this->makeUser(RoleName::FACULTY);

        Faculty::create([
            'user_id' => $user->id,
            'employee_code' => 'FAC'.random_int(1000, 9999),
            'department_id' => $this->department->id,
        ]);

        return $user;
    }

    private function makeStudentUser(): User
    {
        $user = $this->makeUser(RoleName::STUDENT);

        Student::create([
            'user_id' => $user->id,
            'roll_number' => 'CC24'.random_int(1000, 9999),
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 3,
            'cgpa' => 8.0,
            'backlogs' => 0,
        ]);

        return $user;
    }

    private function makeJobPosting(?int $companyId = null, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $companyId ?? $this->company->id,
            'title' => 'Software Engineer I',
            'status' => 'published',
        ], $overrides));
    }

    private function makeInternship(?int $companyId = null, array $overrides = []): Internship
    {
        return Internship::create(array_merge([
            'company_id' => $companyId ?? $this->company->id,
            'title' => 'Backend Intern',
            'status' => 'published',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function drivePayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'title' => 'Campus Recruitment Drive 2026',
            'description' => 'Annual on-campus placement drive.',
            'drive_type' => 'CAMPUS_PLACEMENT',
            'venue' => 'Main Auditorium',
            'scheduled_at' => now()->addDays(10)->toIso8601String(),
            'application_deadline' => now()->addDays(5)->toIso8601String(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function createDrive(User $admin, array $overrides = []): array
    {
        Sanctum::actingAs($admin, ['*']);

        return $this->postJson('/api/admin/placement-drives', $this->drivePayload($overrides))
            ->assertCreated()
            ->json('data');
    }

    private function attachJob(User $admin, int $driveId, JobPosting $jobPosting): void
    {
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/placement-drives/{$driveId}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $jobPosting->id,
        ])->assertCreated();
    }

    private function publishDrive(User $admin, int $driveId): void
    {
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/placement-drives/{$driveId}/publish")->assertOk();
    }

    /**
     * A published drive with one attached job, ready to accept
     * applications/interviews in the integration tests, or to be
     * closed/cancelled in lifecycle tests.
     *
     * @return array{admin: User, drive_id: int, job_posting: JobPosting}
     */
    private function publishedDriveWithJob(): array
    {
        $admin = $this->makeAdmin();
        $jobPosting = $this->makeJobPosting();
        $drive = $this->createDrive($admin);
        $this->attachJob($admin, $drive['id'], $jobPosting);
        $this->publishDrive($admin, $drive['id']);

        return ['admin' => $admin, 'drive_id' => $drive['id'], 'job_posting' => $jobPosting];
    }

    // --- create -------------------------------------------------------

    public function test_admin_can_create_draft_drive(): void
    {
        $data = $this->createDrive($this->makeAdmin());

        $this->assertSame('DRAFT', $data['status']);
        $this->assertSame('Campus Recruitment Drive 2026', $data['title']);
        $this->assertDatabaseHas('placement_drives', ['id' => $data['id'], 'status' => 'DRAFT']);
    }

    public function test_super_admin_can_create_drive(): void
    {
        $data = $this->createDrive($this->makeSuperAdmin());

        $this->assertSame('DRAFT', $data['status']);
    }

    public function test_student_cannot_create_drive(): void
    {
        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $this->postJson('/api/admin/placement-drives', $this->drivePayload())->assertStatus(403);
    }

    public function test_faculty_cannot_create_drive(): void
    {
        Sanctum::actingAs($this->makeFaculty(), ['*']);
        $this->postJson('/api/admin/placement-drives', $this->drivePayload())->assertStatus(403);
    }

    public function test_create_validates_required_fields(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson('/api/admin/placement-drives', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['company_id', 'title', 'drive_type']);
    }

    public function test_create_validates_company_existence(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson('/api/admin/placement-drives', $this->drivePayload(['company_id' => 999999]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['company_id']);
    }

    public function test_create_validates_drive_type_enum(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson('/api/admin/placement-drives', $this->drivePayload(['drive_type' => 'NOT_A_TYPE']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['drive_type']);
    }

    public function test_create_rejects_deadline_after_scheduled_date(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $response = $this->postJson('/api/admin/placement-drives', $this->drivePayload([
            'scheduled_at' => now()->addDays(5)->toIso8601String(),
            'application_deadline' => now()->addDays(10)->toIso8601String(),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['application_deadline']);
    }

    public function test_created_by_is_derived_from_authenticated_user_not_request(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/placement-drives', $this->drivePayload(['created_by' => 999999]));

        $response->assertCreated();
        $this->assertDatabaseHas('placement_drives', ['id' => $response->json('data.id'), 'created_by' => $admin->id]);
    }

    // --- list / detail --------------------------------------------------

    public function test_admin_can_list_drives(): void
    {
        $this->createDrive($this->makeAdmin());

        $response = $this->getJson('/api/admin/placement-drives');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['title' => 'Campus Recruitment Drive 2026']);
    }

    public function test_admin_can_search_drives_by_title(): void
    {
        $admin = $this->makeAdmin();
        $this->createDrive($admin, ['title' => 'Winter Internship Drive']);
        $this->createDrive($admin, ['title' => 'Summer Placement Drive']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/placement-drives?search=Winter');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['title' => 'Winter Internship Drive']);
    }

    public function test_admin_can_filter_drives_by_status(): void
    {
        $fixture = $this->publishedDriveWithJob();
        $this->createDrive($fixture['admin'], ['title' => 'Still Draft Drive']);

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/placement-drives?status=PUBLISHED');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['status' => 'PUBLISHED']);
    }

    public function test_admin_can_filter_drives_by_company(): void
    {
        $admin = $this->makeAdmin();
        $otherCompany = Company::create(['name' => 'Other Corp', 'industry' => 'Finance', 'is_active' => true]);
        $this->createDrive($admin);
        $this->createDrive($admin, ['company_id' => $otherCompany->id, 'title' => 'Other Co Drive']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/admin/placement-drives?company_id={$otherCompany->id}");

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonFragment(['title' => 'Other Co Drive']);
    }

    public function test_admin_list_paginates(): void
    {
        $admin = $this->makeAdmin();
        for ($i = 1; $i <= 3; $i++) {
            $this->createDrive($admin, ['title' => "Drive {$i}"]);
        }

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/placement-drives?per_page=2');

        $response->assertOk();
        $response->assertJsonPath('data.meta.total', 3);
        $response->assertJsonPath('data.meta.last_page', 2);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_admin_can_view_drive_detail(): void
    {
        $fixture = $this->publishedDriveWithJob();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson("/api/admin/placement-drives/{$fixture['drive_id']}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $fixture['drive_id']);
        $response->assertJsonPath('data.status', 'PUBLISHED');
        $response->assertJsonCount(1, 'data.jobs');
        $response->assertJsonCount(0, 'data.internships');
        $response->assertJsonPath('data.application_count', 0);
        $response->assertJsonPath('data.selected_count', 0);
        $response->assertJsonPath('data.interview_count', 0);
        $response->assertJsonStructure(['data' => ['company' => ['id', 'name'], 'status_history']]);
    }

    // --- attach / detach opportunities -----------------------------------

    public function test_admin_can_attach_job(): void
    {
        $admin = $this->makeAdmin();
        $jobPosting = $this->makeJobPosting();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $jobPosting->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.opportunity.type', 'job');
        $this->assertDatabaseHas('placement_drive_opportunities', [
            'placement_drive_id' => $drive['id'],
            'opportunity_type' => JobPosting::class,
            'opportunity_id' => $jobPosting->id,
        ]);
    }

    public function test_admin_can_attach_internship(): void
    {
        $admin = $this->makeAdmin();
        $internship = $this->makeInternship();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/opportunities", [
            'opportunity_type' => 'internship',
            'opportunity_id' => $internship->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.opportunity.type', 'internship');
        $this->assertDatabaseHas('placement_drive_opportunities', [
            'placement_drive_id' => $drive['id'],
            'opportunity_type' => Internship::class,
            'opportunity_id' => $internship->id,
        ]);
    }

    public function test_duplicate_attachment_is_blocked(): void
    {
        $admin = $this->makeAdmin();
        $jobPosting = $this->makeJobPosting();
        $drive = $this->createDrive($admin);
        $this->attachJob($admin, $drive['id'], $jobPosting);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $jobPosting->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, \App\Models\PlacementDriveOpportunity::where('placement_drive_id', $drive['id'])->count());
    }

    public function test_opportunity_from_another_company_is_blocked(): void
    {
        $admin = $this->makeAdmin();
        $otherCompany = Company::create(['name' => 'Other Corp', 'industry' => 'Finance', 'is_active' => true]);
        $foreignJob = $this->makeJobPosting($otherCompany->id);
        $drive = $this->createDrive($admin); // belongs to $this->company

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $foreignJob->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('placement_drive_opportunities', ['placement_drive_id' => $drive['id']]);
    }

    public function test_admin_can_detach_opportunity(): void
    {
        $admin = $this->makeAdmin();
        $jobPosting = $this->makeJobPosting();
        $drive = $this->createDrive($admin);
        Sanctum::actingAs($admin, ['*']);
        $attachment = $this->postJson("/api/admin/placement-drives/{$drive['id']}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $jobPosting->id,
        ])->json('data');

        $response = $this->deleteJson("/api/admin/placement-drives/{$drive['id']}/opportunities/{$attachment['attachment_id']}");

        $response->assertOk();
        $this->assertDatabaseMissing('placement_drive_opportunities', ['id' => $attachment['attachment_id']]);
        $this->assertDatabaseHas('job_postings', ['id' => $jobPosting->id]);
    }

    public function test_cannot_attach_opportunity_after_cancellation(): void
    {
        $admin = $this->makeAdmin();
        $jobPosting = $this->makeJobPosting();
        $another = $this->makeJobPosting();
        $drive = $this->createDrive($admin);
        $this->attachJob($admin, $drive['id'], $jobPosting);
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/placement-drives/{$drive['id']}/cancel", ['reason' => 'Company withdrew.'])->assertOk();

        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $another->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_attach_opportunity_after_closing(): void
    {
        $fixture = $this->publishedDriveWithJob();
        $another = $this->makeJobPosting();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/close")->assertOk();

        $response = $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $another->id,
        ]);

        $response->assertStatus(422);
    }

    // --- publish / close / cancel lifecycle ------------------------------

    public function test_publish_requires_at_least_one_opportunity(): void
    {
        $admin = $this->makeAdmin();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/publish");

        $response->assertStatus(422);
        $this->assertDatabaseHas('placement_drives', ['id' => $drive['id'], 'status' => 'DRAFT']);
    }

    public function test_publish_transitions_draft_to_published(): void
    {
        $admin = $this->makeAdmin();
        $jobPosting = $this->makeJobPosting();
        $drive = $this->createDrive($admin);
        $this->attachJob($admin, $drive['id'], $jobPosting);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/publish");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'PUBLISHED');
    }

    public function test_publishing_does_not_publish_attached_job(): void
    {
        $admin = $this->makeAdmin();
        $jobPosting = $this->makeJobPosting(overrides: ['status' => 'draft']);
        $drive = $this->createDrive($admin);
        $this->attachJob($admin, $drive['id'], $jobPosting);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/placement-drives/{$drive['id']}/publish")->assertOk();

        $this->assertDatabaseHas('job_postings', ['id' => $jobPosting->id, 'status' => 'draft']);
    }

    public function test_cannot_publish_twice(): void
    {
        $fixture = $this->publishedDriveWithJob();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/publish");

        $response->assertStatus(422);
    }

    public function test_close_transitions_published_to_closed(): void
    {
        $fixture = $this->publishedDriveWithJob();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/close");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_cannot_close_a_draft_drive(): void
    {
        $admin = $this->makeAdmin();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/close");

        $response->assertStatus(422);
    }

    public function test_cancel_transitions_draft_to_cancelled(): void
    {
        $admin = $this->makeAdmin();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/cancel", ['reason' => 'Recruiter cancelled.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CANCELLED');
        $this->assertNotNull($response->json('data.cancelled_at'));
    }

    public function test_cancel_transitions_published_to_cancelled(): void
    {
        $fixture = $this->publishedDriveWithJob();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/cancel", ['reason' => 'Company withdrew.']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_cancellation_reason_is_required(): void
    {
        $admin = $this->makeAdmin();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson("/api/admin/placement-drives/{$drive['id']}/cancel", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_cancelling_does_not_reject_applications_or_cancel_interviews(): void
    {
        $fixture = $this->publishedDriveWithJob();

        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/jobs/{$fixture['job_posting']->id}/apply")->json('data.id');

        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/cancel", ['reason' => 'Cancelled.'])->assertOk();

        $this->assertDatabaseHas('applications', ['id' => $applicationId, 'status' => 'APPLIED']);
    }

    public function test_terminal_drive_cannot_be_published_closed_or_cancelled_again(): void
    {
        $admin = $this->makeAdmin();
        $drive = $this->createDrive($admin);
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/placement-drives/{$drive['id']}/cancel", ['reason' => 'Done.'])->assertOk();

        $this->postJson("/api/admin/placement-drives/{$drive['id']}/publish")->assertStatus(422);
        $this->postJson("/api/admin/placement-drives/{$drive['id']}/close")->assertStatus(422);
        $this->postJson("/api/admin/placement-drives/{$drive['id']}/cancel", ['reason' => 'Again.'])->assertStatus(422);
    }

    // --- status history ---------------------------------------------------

    public function test_creation_creates_status_history(): void
    {
        $data = $this->createDrive($this->makeAdmin());

        $this->assertDatabaseHas('placement_drive_status_history', [
            'placement_drive_id' => $data['id'],
            'from_status' => null,
            'to_status' => 'DRAFT',
        ]);
        $this->assertSame(1, PlacementDrive::findOrFail($data['id'])->statusHistory()->count());
    }

    public function test_status_history_is_append_only(): void
    {
        $fixture = $this->publishedDriveWithJob();
        Sanctum::actingAs($fixture['admin'], ['*']);
        $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/close")->assertOk();

        $drive = PlacementDrive::findOrFail($fixture['drive_id']);
        $this->assertSame(3, $drive->statusHistory()->count());
        $this->assertDatabaseHas('placement_drive_status_history', ['placement_drive_id' => $drive->id, 'from_status' => null, 'to_status' => 'DRAFT']);
        $this->assertDatabaseHas('placement_drive_status_history', ['placement_drive_id' => $drive->id, 'from_status' => 'DRAFT', 'to_status' => 'PUBLISHED']);
        $this->assertDatabaseHas('placement_drive_status_history', ['placement_drive_id' => $drive->id, 'from_status' => 'PUBLISHED', 'to_status' => 'CLOSED']);
    }

    // --- update -------------------------------------------------------

    public function test_admin_can_update_scheduling_fields(): void
    {
        $admin = $this->makeAdmin();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->patchJson("/api/admin/placement-drives/{$drive['id']}", ['venue' => 'New Venue Hall']);

        $response->assertOk();
        $response->assertJsonPath('data.venue', 'New Venue Hall');
    }

    public function test_update_cannot_change_status(): void
    {
        $admin = $this->makeAdmin();
        $drive = $this->createDrive($admin);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->patchJson("/api/admin/placement-drives/{$drive['id']}", ['status' => 'PUBLISHED']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('placement_drives', ['id' => $drive['id'], 'status' => 'DRAFT']);
    }

    public function test_update_cannot_change_company_after_published(): void
    {
        $fixture = $this->publishedDriveWithJob();
        $otherCompany = Company::create(['name' => 'Other Corp', 'industry' => 'Finance', 'is_active' => true]);

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->patchJson("/api/admin/placement-drives/{$fixture['drive_id']}", ['company_id' => $otherCompany->id]);

        $response->assertStatus(422);
    }

    // --- security ---------------------------------------------------------

    public function test_unauthenticated_user_cannot_access_admin_placement_drives(): void
    {
        $this->getJson('/api/admin/placement-drives')->assertStatus(401);
        $this->postJson('/api/admin/placement-drives', $this->drivePayload())->assertStatus(401);
    }

    public function test_student_cannot_manage_placement_drives(): void
    {
        $fixture = $this->publishedDriveWithJob();

        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $this->getJson('/api/admin/placement-drives')->assertStatus(403);
        $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/close")->assertStatus(403);
    }

    public function test_faculty_cannot_manage_placement_drives(): void
    {
        $fixture = $this->publishedDriveWithJob();

        Sanctum::actingAs($this->makeFaculty(), ['*']);
        $this->getJson('/api/admin/placement-drives')->assertStatus(403);
        $this->postJson("/api/admin/placement-drives/{$fixture['drive_id']}/close")->assertStatus(403);
    }
}
