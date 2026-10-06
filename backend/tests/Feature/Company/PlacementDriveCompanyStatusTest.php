<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyStatus;
use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Placement drives vs. company approval state (see
 * App\Services\PlacementDriveService::assertCompanyCanRunDrives() and
 * PlacementDrivePolicy::view()):
 *
 * - APPROVED: the full existing drive workflow.
 * - PENDING: drafts can be prepared, but not published; hidden from students.
 * - REJECTED / SUSPENDED: no new drives, no edits/attachments/publishing;
 *   existing drives can still be closed or cancelled; hidden from students.
 * - Placement Admin / Super Admin keep seeing every drive, with the
 *   company's status in the payload.
 */
class PlacementDriveCompanyStatusTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Batch $batch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
        $this->admin = $this->makeUser(RoleName::PLACEMENT_ADMIN);
    }

    private function makeUser(RoleName $role): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => strtolower($role->value).'-'.uniqid().'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => Role::where('name', $role->value)->firstOrFail()->id,
        ]);
    }

    private function makeStudent(): User
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

    private function makeCompany(CompanyStatus $status, string $name = 'NovaTech Solutions'): Company
    {
        $company = Company::create(['name' => $name, 'industry' => 'Software', 'is_active' => true]);
        $company->forceFill(['status' => $status])->save();

        return $company;
    }

    private function setStatus(Company $company, CompanyStatus $status): void
    {
        $company->forceFill(['status' => $status])->save();
    }

    private function makeJob(Company $company): JobPosting
    {
        return JobPosting::create(['company_id' => $company->id, 'title' => 'Software Engineer I', 'status' => 'published']);
    }

    /** @return array<string, mixed> */
    private function drivePayload(Company $company, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $company->id,
            'title' => 'Campus Recruitment Drive 2026',
            'drive_type' => 'CAMPUS_PLACEMENT',
            'venue' => 'Main Auditorium',
            'scheduled_at' => now()->addDays(10)->toIso8601String(),
            'application_deadline' => now()->addDays(5)->toIso8601String(),
        ], $overrides);
    }

    /** Builds a drive through the real admin endpoints while the company is APPROVED. */
    private function makeDrive(Company $company, bool $publish = true): int
    {
        Sanctum::actingAs($this->admin, ['*']);

        $driveId = $this->postJson('/api/admin/placement-drives', $this->drivePayload($company))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/admin/placement-drives/{$driveId}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $this->makeJob($company)->id,
        ])->assertCreated();

        if ($publish) {
            $this->postJson("/api/admin/placement-drives/{$driveId}/publish")->assertOk();
        }

        return $driveId;
    }

    private function assertStudentSees(int $driveId, bool $visible): void
    {
        Sanctum::actingAs($this->makeStudent(), ['*']);

        $ids = collect($this->getJson('/api/student/placement-drives')->assertOk()->json('data.items'))->pluck('id');
        $this->assertSame($visible, $ids->contains($driveId));

        $this->getJson("/api/student/placement-drives/{$driveId}")->assertStatus($visible ? 200 : 403);
    }

    // --- approved -----------------------------------------------------

    public function test_approved_company_keeps_the_full_drive_workflow(): void
    {
        $company = $this->makeCompany(CompanyStatus::APPROVED);
        $driveId = $this->makeDrive($company);

        $this->assertStudentSees($driveId, true);

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/placement-drives/{$driveId}", ['venue' => 'Block B'])->assertOk();
        $this->postJson("/api/admin/placement-drives/{$driveId}/close")->assertOk();
    }

    public function test_student_payload_carries_company_status(): void
    {
        $driveId = $this->makeDrive($this->makeCompany(CompanyStatus::APPROVED));

        Sanctum::actingAs($this->makeStudent(), ['*']);
        $this->getJson("/api/student/placement-drives/{$driveId}")->assertJsonPath('data.company.status', 'APPROVED');
    }

    // --- pending ------------------------------------------------------

    public function test_pending_company_drive_can_be_prepared_but_not_published(): void
    {
        $company = $this->makeCompany(CompanyStatus::PENDING);
        $driveId = $this->makeDrive($company, publish: false);

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/placement-drives/{$driveId}", ['venue' => 'Block B'])->assertOk();

        $this->postJson("/api/admin/placement-drives/{$driveId}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'NovaTech Solutions is still waiting for Super Admin approval, so its drive can\'t be published yet.');

        $this->assertDatabaseHas('placement_drives', ['id' => $driveId, 'status' => 'DRAFT']);

        // Once approved, the same draft publishes normally.
        $this->setStatus($company, CompanyStatus::APPROVED);
        $this->postJson("/api/admin/placement-drives/{$driveId}/publish")->assertOk();
        $this->assertStudentSees($driveId, true);
    }

    public function test_published_drive_of_a_pending_company_is_hidden_from_students(): void
    {
        $company = $this->makeCompany(CompanyStatus::APPROVED);
        $driveId = $this->makeDrive($company);
        $this->setStatus($company, CompanyStatus::PENDING);

        $this->assertStudentSees($driveId, false);
    }

    // --- rejected -----------------------------------------------------

    public function test_rejected_company_cannot_get_a_new_drive(): void
    {
        $company = $this->makeCompany(CompanyStatus::REJECTED);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/admin/placement-drives', $this->drivePayload($company))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'NovaTech Solutions is rejected, so its placement drives can only be closed or cancelled.');

        $this->assertDatabaseCount('placement_drives', 0);
    }

    public function test_rejected_companys_existing_draft_is_frozen_but_can_be_cancelled(): void
    {
        $company = $this->makeCompany(CompanyStatus::APPROVED);
        $driveId = $this->makeDrive($company, publish: false);
        $this->setStatus($company, CompanyStatus::REJECTED);

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/placement-drives/{$driveId}", ['venue' => 'Block B'])->assertUnprocessable();
        $this->postJson("/api/admin/placement-drives/{$driveId}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $this->makeJob($company)->id,
        ])->assertUnprocessable();
        $this->postJson("/api/admin/placement-drives/{$driveId}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'NovaTech Solutions is rejected, so its placement drives can only be closed or cancelled.');

        $this->postJson("/api/admin/placement-drives/{$driveId}/cancel", ['reason' => 'Company registration rejected.'])->assertOk();
        $this->assertDatabaseHas('placement_drives', ['id' => $driveId, 'status' => 'CANCELLED']);
    }

    public function test_published_drive_of_a_rejected_company_is_hidden_from_students(): void
    {
        $company = $this->makeCompany(CompanyStatus::APPROVED);
        $driveId = $this->makeDrive($company);
        $this->setStatus($company, CompanyStatus::REJECTED);

        $this->assertStudentSees($driveId, false);
    }

    public function test_draft_cannot_be_moved_to_a_rejected_company(): void
    {
        $driveId = $this->makeDrive($this->makeCompany(CompanyStatus::APPROVED), publish: false);
        $rejected = $this->makeCompany(CompanyStatus::REJECTED, 'Rejected Co');

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/placement-drives/{$driveId}", ['company_id' => $rejected->id])->assertUnprocessable();
    }

    // --- suspended ----------------------------------------------------

    public function test_suspended_company_cannot_get_a_new_drive(): void
    {
        $company = $this->makeCompany(CompanyStatus::SUSPENDED);

        Sanctum::actingAs($this->makeUser(RoleName::SUPER_ADMIN), ['*']);
        $this->postJson('/api/admin/placement-drives', $this->drivePayload($company))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'NovaTech Solutions is suspended, so its placement drives can only be closed or cancelled.');
    }

    public function test_suspended_companys_published_drive_is_hidden_frozen_and_closable(): void
    {
        $company = $this->makeCompany(CompanyStatus::APPROVED);
        $driveId = $this->makeDrive($company);
        $this->setStatus($company, CompanyStatus::SUSPENDED);

        $this->assertStudentSees($driveId, false);

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/placement-drives/{$driveId}", ['venue' => 'Block B'])->assertUnprocessable();
        $this->postJson("/api/admin/placement-drives/{$driveId}/opportunities", [
            'opportunity_type' => 'job',
            'opportunity_id' => $this->makeJob($company)->id,
        ])->assertUnprocessable();

        $this->postJson("/api/admin/placement-drives/{$driveId}/close")->assertOk();
        $this->assertDatabaseHas('placement_drives', ['id' => $driveId, 'status' => 'CLOSED']);
    }

    public function test_reactivated_company_drive_becomes_visible_again(): void
    {
        $company = $this->makeCompany(CompanyStatus::APPROVED);
        $driveId = $this->makeDrive($company);

        $this->setStatus($company, CompanyStatus::SUSPENDED);
        $this->assertStudentSees($driveId, false);

        $this->setStatus($company, CompanyStatus::APPROVED);
        $this->assertStudentSees($driveId, true);
    }

    // --- admin visibility ---------------------------------------------

    public function test_admins_still_see_every_drive_with_company_status(): void
    {
        $drives = [];
        foreach (CompanyStatus::cases() as $status) {
            $company = $this->makeCompany(CompanyStatus::APPROVED, "{$status->value} Co");
            $drives[$status->value] = $this->makeDrive($company);
            $this->setStatus($company, $status);
        }

        foreach ([$this->admin, $this->makeUser(RoleName::SUPER_ADMIN)] as $viewer) {
            Sanctum::actingAs($viewer, ['*']);

            $items = collect($this->getJson('/api/admin/placement-drives')->assertOk()->json('data.items'))->keyBy('id');
            $this->assertCount(4, $items);

            foreach ($drives as $status => $driveId) {
                $this->assertSame($status, $items[$driveId]['company']['status']);
                $this->getJson("/api/admin/placement-drives/{$driveId}")
                    ->assertOk()
                    ->assertJsonPath('data.company.status', $status);
            }
        }
    }
}
