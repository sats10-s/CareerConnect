<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyMemberRole;
use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * Company self-registration, the pending gate, Super Admin review
 * (approve / reject / suspend / reactivate), cross-company isolation and
 * hiding unapproved companies' postings from students.
 */
class CompanyRegistrationTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacementFixtures();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Robotics',
            'industry' => 'Robotics',
            'website' => 'https://www.acmerobotics.io',
            'headquarters' => 'Bengaluru',
            'company_size' => '51-200',
            'contact_name' => 'Priya Rao',
            'email' => 'priya@acmerobotics.io',
            'contact_phone' => '+91 98765 43210',
            'password' => 'Recruit@123',
            'password_confirmation' => 'Recruit@123',
        ], $overrides);
    }

    /** Register through the real endpoint and return the owner login. */
    private function registerCompany(array $overrides = []): User
    {
        $this->postJson('/api/company/register', $this->payload($overrides))->assertCreated();

        return User::where('email', strtolower($overrides['email'] ?? 'priya@acmerobotics.io'))->firstOrFail();
    }

    public function test_registration_creates_a_pending_company_and_its_owner_login(): void
    {
        $this->postJson('/api/company/register', $this->payload(['status' => 'APPROVED', 'source' => 'ADMIN']))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'COMPANY')
            ->assertJsonPath('data.company.status', 'PENDING')
            ->assertJsonPath('data.company.member_role', 'OWNER')
            ->assertJsonMissingPath('data.password');

        $company = Company::where('name', 'Acme Robotics')->firstOrFail();
        $this->assertSame(CompanyStatus::PENDING, $company->status);
        $this->assertSame(CompanySource::SELF_REGISTERED, $company->source);
        $this->assertSame('priya@acmerobotics.io', $company->contact_email);

        $owner = User::where('email', 'priya@acmerobotics.io')->firstOrFail();
        $this->assertSame($company->id, $owner->company_id);
        $this->assertSame(CompanyMemberRole::OWNER, $owner->company_member_role);
        $this->assertSame(RoleName::COMPANY, $owner->roleName());
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.registered', 'subject_id' => $company->id]);
    }

    public function test_registration_validates_and_respects_the_setting(): void
    {
        $this->postJson('/api/company/register', [])->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'contact_name', 'email', 'password']);

        $this->postJson('/api/company/register', $this->payload(['company_name' => 'NovaTech Solutions']))
            ->assertStatus(422)->assertJsonValidationErrors('company_name');

        app(SettingsService::class)->update(['allow_company_registration' => false], null);
        $this->postJson('/api/company/register', $this->payload())->assertStatus(422)->assertJsonValidationErrors('company_name');
        $this->assertDatabaseMissing('companies', ['name' => 'Acme Robotics']);
        $this->assertDatabaseMissing('users', ['email' => 'priya@acmerobotics.io']);
        $this->getJson('/api/meta/settings')->assertJsonPath('allow_company_registration', false);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/company/register', [])->assertStatus(422);
        }
        $this->postJson('/api/company/register', [])->assertStatus(429);
    }

    public function test_possible_duplicates_are_flagged_by_name_and_website_domain(): void
    {
        Company::create(['name' => 'Acme Robotics Pvt Ltd', 'website' => 'https://acmerobotics.io/about', 'is_active' => true]);
        $this->registerCompany();

        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);
        $response = $this->getJson('/api/super-admin/companies?status=PENDING')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.counts.PENDING', 1)
            ->assertJsonPath('data.items.0.name', 'Acme Robotics')
            ->assertJsonPath('data.items.0.owner.email', 'priya@acmerobotics.io');

        $duplicates = $response->json('data.items.0.possible_duplicates');
        $this->assertCount(1, $duplicates);
        $this->assertSame('Acme Robotics Pvt Ltd', $duplicates[0]['name']);
        $this->assertEqualsCanonicalizing(['name', 'website'], $duplicates[0]['reasons']);
    }

    public function test_pending_company_can_only_reach_me_and_profile(): void
    {
        $owner = $this->registerCompany();
        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/company/me')->assertOk()->assertJsonPath('data.status', 'PENDING');
        $this->patchJson('/api/company/profile', ['headquarters' => 'Hyderabad'])->assertOk()->assertJsonPath('data.headquarters', 'Hyderabad');
        $this->getJson('/api/company/dashboard')->assertForbidden()->assertJsonPath('errors.company_status.0', 'PENDING');

        // and no other role's area
        $this->getJson('/api/admin/companies')->assertForbidden();
        $this->getJson('/api/super-admin/companies')->assertForbidden();
        $this->getJson('/api/student/opportunities')->assertForbidden();

        // non-company roles cannot use the company portal at all
        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $this->getJson('/api/company/me')->assertForbidden();
    }

    public function test_only_super_admin_can_review_and_approval_unlocks_the_portal(): void
    {
        $owner = $this->registerCompany();
        $company = $owner->company;

        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->getJson('/api/super-admin/companies')->assertForbidden();
        $this->postJson("/api/super-admin/companies/{$company->id}/approve")->assertForbidden();
        $this->assertSame(CompanyStatus::PENDING, $company->fresh()->status);

        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($superAdmin, ['*']);
        $this->postJson("/api/super-admin/companies/{$company->id}/approve", ['note' => 'Verified GST'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.reviewed_by.id', $superAdmin->id);
        $this->postJson("/api/super-admin/companies/{$company->id}/approve")->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'company.approved', 'subject_id' => $company->id]);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id, 'type' => 'COMPANY_STATUS_CHANGED']);

        Sanctum::actingAs($owner->fresh(), ['*']);
        $this->getJson('/api/company/dashboard')->assertOk()->assertJsonPath('data.company.status', 'APPROVED');
    }

    public function test_reject_and_suspend_need_a_reason_and_follow_valid_transitions(): void
    {
        $owner = $this->registerCompany();
        $company = $owner->company;
        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);

        $this->postJson("/api/super-admin/companies/{$company->id}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/super-admin/companies/{$company->id}/suspend", ['reason' => 'Not approved yet'])->assertStatus(422);
        $this->postJson("/api/super-admin/companies/{$company->id}/reject", ['reason' => 'Could not verify the company'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.review_note', 'Could not verify the company');
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.rejected']);

        Sanctum::actingAs($owner->fresh(), ['*']);
        $this->getJson('/api/auth/me')->assertJsonPath('data.company.status', 'REJECTED')->assertJsonPath('data.company.review_note', 'Could not verify the company');
        $this->getJson('/api/company/dashboard')->assertForbidden()->assertJsonPath('errors.company_status.0', 'REJECTED');

        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);
        $this->postJson("/api/super-admin/companies/{$company->id}/approve")->assertOk();
        $this->postJson("/api/super-admin/companies/{$company->id}/suspend")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/super-admin/companies/{$company->id}/suspend", ['reason' => 'Fake job offers reported'])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->postJson("/api/super-admin/companies/{$company->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.suspended']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.reactivated']);
    }

    public function test_suspension_revokes_recruiter_tokens(): void
    {
        $owner = $this->registerCompany();
        $company = $owner->company;
        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);
        $this->postJson("/api/super-admin/companies/{$company->id}/approve")->assertOk();

        $token = $owner->createToken('careerconnect-api')->plainTextToken;
        $owner->createToken('second-device');

        $this->postJson("/api/super-admin/companies/{$company->id}/suspend", ['reason' => 'Fake job offers reported'])->assertOk();
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id, 'title' => 'Your company account is suspended']);

        auth()->forgetGuards();
        $this->withToken($token)->getJson('/api/company/me')->assertUnauthorized();
    }

    public function test_a_recruiter_can_only_ever_touch_its_own_company(): void
    {
        $ownerA = $this->registerCompany();
        $ownerB = $this->registerCompany(['company_name' => 'Beta Analytics', 'website' => 'https://beta.example', 'email' => 'lead@beta.example']);
        $companyB = $ownerB->company;

        Sanctum::actingAs($ownerA, ['*']);
        $this->patchJson('/api/company/profile', [
            'name' => 'Acme Robotics International',
            'company_id' => $companyB->id,
            'id' => $companyB->id,
            'status' => 'APPROVED',
        ])->assertOk()->assertJsonPath('data.id', $ownerA->company_id)->assertJsonPath('data.status', 'PENDING');

        $this->assertSame('Beta Analytics', $companyB->fresh()->name);
        $this->assertSame(CompanyStatus::PENDING, $ownerA->company->fresh()->status);
        $this->getJson('/api/company/me')->assertJsonPath('data.name', 'Acme Robotics International');

        $this->getJson("/api/admin/companies/{$companyB->id}")->assertForbidden();
        $this->getJson("/api/super-admin/companies/{$companyB->id}")->assertForbidden();
        $this->patchJson('/api/company/profile', ['name' => 'Beta Analytics'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_students_only_see_postings_from_approved_companies(): void
    {
        $owner = $this->registerCompany();
        $pending = $owner->company;
        $suspended = Company::create(['name' => 'Gone Corp', 'is_active' => true]);
        $suspended->status = CompanyStatus::SUSPENDED;
        $suspended->save();

        $visible = $this->makeJobPosting(['title' => 'Approved Co Engineer']);
        $hiddenJob = $this->makeJobPosting(['title' => 'Pending Co Engineer', 'company_id' => $pending->id]);
        $this->makeInternship(['title' => 'Suspended Co Intern', 'company_id' => $suspended->id]);

        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $response = $this->getJson('/api/student/opportunities')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->assertSame([$visible->title], collect($response->json('data.items'))->pluck('title')->all());
        $this->getJson("/api/student/opportunities/jobs/{$hiddenJob->id}")->assertForbidden();
        $this->postJson("/api/student/opportunities/jobs/{$hiddenJob->id}/apply")->assertForbidden();

        $pending->status = CompanyStatus::APPROVED;
        $pending->save();
        $this->getJson('/api/student/opportunities')->assertJsonPath('data.meta.total', 2);
        $this->getJson("/api/student/opportunities/jobs/{$hiddenJob->id}")->assertOk();
    }

    public function test_admin_company_list_shows_and_filters_by_status(): void
    {
        $this->registerCompany();
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $this->postJson('/api/admin/companies', ['name' => 'Office Added Ltd'])->assertCreated()
            ->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.source', 'ADMIN');

        $this->getJson('/api/admin/companies?status=PENDING')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Acme Robotics')
            ->assertJsonPath('data.items.0.status', 'PENDING');
        $this->getJson('/api/admin/companies?status=APPROVED')->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/admin/companies?status=BOGUS')->assertStatus(422);
    }
}
