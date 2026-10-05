<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyMemberRole;
use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use App\Enums\RoleName;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\StudentSkill;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * The recruiter side of the company portal: postings (with Placement Admin
 * review), applicants (privacy-safe), interviews, team logins, and
 * isolation between companies.
 */
class CompanyPortalTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacementFixtures();
    }

    private function makeCompanyOwner(string $name = 'Acme Robotics', CompanyStatus $status = CompanyStatus::APPROVED): User
    {
        $company = Company::create(['name' => $name, 'is_active' => true, 'contact_name' => 'Owner', 'contact_email' => 'owner@'.strtolower(str_replace(' ', '', $name)).'.test']);
        $company->status = $status;
        $company->source = CompanySource::SELF_REGISTERED;
        $company->save();

        return $this->makeUser(RoleName::COMPANY, [
            'name' => "{$name} Owner",
            'company_id' => $company->id,
            'company_member_role' => CompanyMemberRole::OWNER,
        ]);
    }

    /** A published job for the owner's company plus a student who applied to it. */
    private function jobWithApplicant(User $owner): array
    {
        $job = $this->makeJobPosting(['company_id' => $owner->company_id, 'title' => 'Robotics Engineer']);
        $student = $this->makeStudentUser(['name' => 'Asha Verma', 'phone' => '9876543210', 'personal_email' => 'asha.home@example.com']);
        StudentSkill::create(['student_id' => $student->student->id, 'skill_name' => 'ROS']);

        Sanctum::actingAs($student, ['*']);
        $applicationId = $this->postJson("/api/student/opportunities/jobs/{$job->id}/apply")->assertCreated()->json('data.id');

        return [$job, $student, $applicationId];
    }

    public function test_recruiter_postings_go_through_placement_admin_review(): void
    {
        $owner = $this->makeCompanyOwner();
        $admin = $this->makeAdmin();
        Sanctum::actingAs($owner, ['*']);

        $other = Company::create(['name' => 'Someone Else', 'is_active' => true]);
        $id = $this->postJson('/api/company/postings', [
            'type' => 'job',
            'title' => 'Firmware Engineer',
            'salary_range' => '12-16 LPA',
            'company_id' => $other->id,
            'status' => 'published',
            'minimum_cgpa' => 9.5,
            'source' => 'ADMIN',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_state', 'draft')
            ->assertJsonPath('data.source', 'COMPANY')
            ->assertJsonPath('data.company.id', $owner->company_id)
            ->assertJsonPath('data.eligibility.minimum_cgpa', null)
            ->json('data.id');

        $this->patchJson("/api/company/postings/job/{$id}", ['location' => 'Pune'])->assertOk()->assertJsonPath('data.location', 'Pune');
        $this->postJson("/api/company/postings/job/{$id}/submit")->assertOk()->assertJsonPath('data.review_state', 'in_review')->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/company/postings/job/{$id}/submit")->assertStatus(422);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $admin->id, 'type' => 'COMPANY_POSTING_SUBMITTED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.posting_submitted', 'subject_id' => $id]);

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/admin/jobs?awaiting_review=1')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.id', $id);
        $this->patchJson("/api/admin/jobs/{$id}", ['status' => 'published', 'minimum_cgpa' => 7])->assertOk();
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id, 'type' => 'COMPANY_POSTING_PUBLISHED']);
        $this->getJson('/api/admin/jobs?awaiting_review=1')->assertJsonPath('data.meta.total', 0);

        Sanctum::actingAs($owner, ['*']);
        $this->patchJson("/api/company/postings/job/{$id}", ['title' => 'Changed'])->assertStatus(422);
        $this->getJson('/api/company/postings')->assertOk()->assertJsonPath('data.items.0.review_state', 'live');

        Sanctum::actingAs($this->makeStudentUser(), ['*']);
        $this->getJson("/api/student/opportunities/jobs/{$id}")->assertOk();

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/company/postings/job/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_submit_publishes_directly_when_review_is_off(): void
    {
        app(SettingsService::class)->update(['company_postings_require_review' => false], null);
        $owner = $this->makeCompanyOwner();
        Sanctum::actingAs($owner, ['*']);

        $id = $this->postJson('/api/company/postings', ['type' => 'internship', 'title' => 'Summer Intern', 'stipend' => '20k/month', 'duration_months' => 2])
            ->assertCreated()->assertJsonPath('data.type', 'internship')->assertJsonPath('data.stipend', '20k/month')->json('data.id');
        $this->postJson("/api/company/postings/internship/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.review_state', 'live');
    }

    public function test_applicants_never_expose_contact_details_and_status_changes_use_the_normal_rules(): void
    {
        $owner = $this->makeCompanyOwner();
        [, $student, $applicationId] = $this->jobWithApplicant($owner);

        Sanctum::actingAs($owner, ['*']);
        $list = $this->getJson('/api/company/applications')->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.student.name', 'Asha Verma');
        $detail = $this->getJson("/api/company/applications/{$applicationId}")->assertOk()
            ->assertJsonPath('data.student.skills.0', 'ROS');

        foreach ([$list->getContent(), $detail->getContent()] as $body) {
            $this->assertStringNotContainsString('9876543210', $body);
            $this->assertStringNotContainsString('asha.home@example.com', $body);
            $this->assertStringNotContainsString($student->email, $body);
            $this->assertStringNotContainsString('resume', $body);
            $this->assertStringNotContainsString('"phone"', $body);
        }

        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'WITHDRAWN'])->assertStatus(422);
        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'SELECTED'])->assertStatus(422);
        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'UNDER_REVIEW'])->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $student->id, 'type' => 'APPLICATION_STATUS_CHANGED']);
    }

    public function test_recruiter_schedules_and_manages_interviews(): void
    {
        $owner = $this->makeCompanyOwner();
        [, $student, $applicationId] = $this->jobWithApplicant($owner);
        Sanctum::actingAs($owner, ['*']);

        $interview = ['round' => 1, 'scheduled_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String(), 'mode' => 'ONLINE', 'meeting_url' => 'https://meet.example.com/abc'];
        $this->postJson("/api/company/applications/{$applicationId}/interviews", $interview)->assertStatus(422); // still APPLIED

        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'SHORTLISTED'])->assertOk();
        $id = $this->postJson("/api/company/applications/{$applicationId}/interviews", $interview)->assertCreated()
            ->assertJsonPath('data.status', 'SCHEDULED')
            ->assertJsonMissingPath('data.student.email')
            ->json('data.id');
        $this->getJson("/api/company/applications/{$applicationId}")->assertJsonPath('data.status', 'INTERVIEW');
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $student->id, 'type' => 'INTERVIEW_SCHEDULED']);

        $this->getJson('/api/company/interviews?upcoming=1')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/company/dashboard')->assertOk()->assertJsonPath('data.stats.live_postings', 1)->assertJsonPath('data.stats.shortlisted', 1)->assertJsonPath('data.upcoming_interviews.0.id', $id);
        $this->postJson("/api/company/interviews/{$id}/reschedule", ['scheduled_at' => now()->addDays(4)->setTime(11, 0)->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'RESCHEDULED');
        $this->postJson("/api/company/interviews/{$id}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/company/interviews/{$id}/complete", ['remarks' => 'Strong'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_companies_are_isolated_from_each_other(): void
    {
        $ownerA = $this->makeCompanyOwner('Acme Robotics');
        $ownerB = $this->makeCompanyOwner('Beta Analytics');
        [$jobA, , $applicationId] = $this->jobWithApplicant($ownerA);

        Sanctum::actingAs($ownerA, ['*']);
        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'SHORTLISTED'])->assertOk();
        $interviewId = $this->postJson("/api/company/applications/{$applicationId}/interviews", ['round' => 1, 'scheduled_at' => now()->addDay()->toIso8601String(), 'mode' => 'OFFLINE', 'venue' => 'Room 4'])->assertCreated()->json('data.id');

        Sanctum::actingAs($ownerB, ['*']);
        $this->getJson('/api/company/postings')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson("/api/company/postings/job/{$jobA->id}")->assertNotFound();
        $this->patchJson("/api/company/postings/job/{$jobA->id}", ['title' => 'Hijack'])->assertNotFound();
        $this->postJson("/api/company/postings/job/{$jobA->id}/close")->assertNotFound();
        $this->getJson('/api/company/applications')->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/company/applications/{$applicationId}")->assertNotFound();
        $this->patchJson("/api/company/applications/{$applicationId}/status", ['status' => 'REJECTED'])->assertNotFound();
        $this->postJson("/api/company/applications/{$applicationId}/interviews", ['round' => 2, 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'mode' => 'OFFLINE', 'venue' => 'X'])->assertForbidden();
        $this->getJson('/api/company/interviews')->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/company/interviews/{$interviewId}")->assertNotFound();
        $this->postJson("/api/company/interviews/{$interviewId}/cancel", ['reason' => 'Not mine'])->assertForbidden();
        $this->getJson('/api/company/members')->assertJsonCount(1, 'data.items');
        $this->patchJson("/api/company/members/{$ownerA->id}/status", ['is_active' => false])->assertNotFound();

        $this->assertSame('Robotics Engineer', $jobA->fresh()->title);
    }

    public function test_owner_manages_recruiter_logins(): void
    {
        $owner = $this->makeCompanyOwner();
        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/company/members')->assertOk()->assertJsonPath('data.can_manage', true);
        $recruiterId = $this->postJson('/api/company/members', ['name' => 'Ravi', 'email' => 'Ravi@AcmeRobotics.test', 'password' => 'Recruit@123', 'password_confirmation' => 'Recruit@123'])
            ->assertCreated()->assertJsonPath('data.member_role', 'RECRUITER')->assertJsonPath('data.email', 'ravi@acmerobotics.test')->json('data.id');
        $this->patchJson("/api/company/members/{$owner->id}/status", ['is_active' => false])->assertStatus(422);

        $recruiter = User::findOrFail($recruiterId);
        $this->assertSame($owner->company_id, $recruiter->company_id);
        $recruiter->createToken('t');

        Sanctum::actingAs($recruiter, ['*']);
        $this->postJson('/api/company/postings', ['type' => 'job', 'title' => 'QA Engineer'])->assertCreated();
        $this->getJson('/api/company/members')->assertJsonPath('data.can_manage', false);
        $this->postJson('/api/company/members', ['name' => 'X', 'email' => 'x@acmerobotics.test', 'password' => 'Recruit@123', 'password_confirmation' => 'Recruit@123'])->assertForbidden();

        Sanctum::actingAs($owner, ['*']);
        $this->patchJson("/api/company/members/{$recruiterId}/status", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $recruiter->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.member_deactivated']);
    }

    public function test_pending_company_cannot_use_the_recruiting_features(): void
    {
        $owner = $this->makeCompanyOwner('Waiting Co', CompanyStatus::PENDING);
        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/company/postings')->assertForbidden();
        $this->postJson('/api/company/postings', ['type' => 'job', 'title' => 'X'])->assertForbidden();
        $this->getJson('/api/company/applications')->assertForbidden();
        $this->getJson('/api/company/interviews')->assertForbidden();
        $this->getJson('/api/company/members')->assertForbidden();
        $this->assertSame(0, JobPosting::where('company_id', $owner->company_id)->count());
    }
}
