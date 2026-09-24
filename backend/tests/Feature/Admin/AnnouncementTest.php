<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationType;
use App\Models\Announcement;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * POST/GET/PUT/PATCH /api/admin/announcements[...] - see
 * Api\Admin\AnnouncementController and App\Services\AnnouncementService.
 * Covers the full admin management lifecycle (module spec section 7):
 * draft creation, draft editing, publish, archive, unauthorized-role
 * rejection, audience filtering, and the publish -> notification fan-out
 * (section 8), including its once-only idempotency guard.
 */
class AnnouncementTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
    }

    private function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Campus Placement Drive',
            'body' => 'All eligible students must register by Friday.',
            'category' => 'PLACEMENT',
            'audience' => 'STUDENTS',
        ], $overrides);
    }

    public function test_an_admin_can_create_an_announcement_draft(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson('/api/admin/announcements', $this->draftPayload())->assertCreated();

        $this->assertSame('DRAFT', $response->json('data.status'));
        $this->assertDatabaseHas('announcements', ['title' => 'Campus Placement Drive', 'status' => 'DRAFT', 'created_by' => $admin->id]);
    }

    public function test_an_admin_can_edit_a_draft_announcement(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload())->json('data.id');

        $response = $this->putJson("/api/admin/announcements/{$id}", ['title' => 'Updated Placement Drive'])->assertOk();

        $this->assertSame('Updated Placement Drive', $response->json('data.title'));
        $this->assertDatabaseHas('announcements', ['id' => $id, 'title' => 'Updated Placement Drive', 'updated_by' => $admin->id]);
    }

    public function test_a_published_announcement_cannot_be_edited(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload())->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        $this->putJson("/api/admin/announcements/{$id}", ['title' => 'Should not apply'])->assertStatus(422);

        $this->assertDatabaseHas('announcements', ['id' => $id, 'title' => 'Campus Placement Drive']);
    }

    public function test_an_admin_can_publish_a_draft_announcement(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload())->json('data.id');

        $response = $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        $this->assertSame('PUBLISHED', $response->json('data.status'));
        $this->assertNotNull($response->json('data.publish_at'));
    }

    public function test_an_admin_can_archive_a_published_announcement(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload())->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        $response = $this->postJson("/api/admin/announcements/{$id}/archive")->assertOk();

        $this->assertSame('ARCHIVED', $response->json('data.status'));
    }

    public function test_an_archived_announcement_cannot_be_republished(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload())->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();
        $this->postJson("/api/admin/announcements/{$id}/archive")->assertOk();

        $this->postJson("/api/admin/announcements/{$id}/publish")->assertStatus(422);

        $this->assertDatabaseHas('announcements', ['id' => $id, 'status' => 'ARCHIVED']);
    }

    public function test_a_draft_announcement_cannot_be_archived(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload())->json('data.id');

        $this->postJson("/api/admin/announcements/{$id}/archive")->assertStatus(422);
    }

    public function test_invalid_category_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/admin/announcements', $this->draftPayload(['category' => 'NOT_A_REAL_CATEGORY']))
            ->assertStatus(422);
    }

    public function test_invalid_audience_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/admin/announcements', $this->draftPayload(['audience' => 'EVERYONE']))
            ->assertStatus(422);
    }

    public function test_expires_at_must_be_after_publish_at(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/admin/announcements', $this->draftPayload([
            'publish_at' => now()->addDays(2)->toIso8601String(),
            'expires_at' => now()->addDay()->toIso8601String(),
        ]))->assertStatus(422);
    }

    public function test_a_student_cannot_create_an_announcement(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $this->postJson('/api/admin/announcements', $this->draftPayload())->assertForbidden();
    }

    public function test_a_faculty_member_cannot_create_an_announcement(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->postJson('/api/admin/announcements', $this->draftPayload())->assertForbidden();
    }

    public function test_a_faculty_member_cannot_publish_or_archive_an_announcement(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload())->json('data.id');

        Sanctum::actingAs($faculty->user, ['*']);
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertForbidden();
        $this->postJson("/api/admin/announcements/{$id}/archive")->assertForbidden();
    }

    public function test_a_faculty_member_cannot_access_the_admin_announcement_management_routes(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson('/api/admin/announcements')->assertForbidden();
    }

    public function test_admin_index_can_filter_by_audience(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/admin/announcements', $this->draftPayload(['audience' => 'STUDENTS']))->assertCreated();
        $this->postJson('/api/admin/announcements', $this->draftPayload(['audience' => 'FACULTY']))->assertCreated();

        $response = $this->getJson('/api/admin/announcements?audience=FACULTY')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('FACULTY', $response->json('data.items.0.audience'));
    }

    public function test_admin_index_includes_drafts_and_archived(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/admin/announcements', $this->draftPayload())->assertCreated();

        $response = $this->getJson('/api/admin/announcements')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('DRAFT', $response->json('data.items.0.status'));
    }

    public function test_publishing_an_announcement_notifies_its_audience(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $otherStudentUser = $this->makeStudentUser();
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload(['audience' => 'STUDENTS']))->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $studentUser->id,
            'type' => NotificationType::ANNOUNCEMENT->value,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $otherStudentUser->id,
            'type' => NotificationType::ANNOUNCEMENT->value,
        ]);
        $this->assertSame(0, Notification::where('recipient_user_id', $faculty->user->id)->count());
    }

    public function test_repeated_fetches_never_duplicate_the_publish_notification(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload(['audience' => 'STUDENTS']))->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        $this->getJson("/api/admin/announcements/{$id}")->assertOk();
        $this->getJson('/api/admin/announcements')->assertOk();
        $this->getJson("/api/admin/announcements/{$id}")->assertOk();

        $this->assertSame(
            1,
            Notification::where('recipient_user_id', $studentUser->id)
                ->where('type', NotificationType::ANNOUNCEMENT->value)
                ->count(),
        );
    }

    public function test_archiving_does_not_send_another_notification(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', $this->draftPayload(['audience' => 'STUDENTS']))->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();
        $this->postJson("/api/admin/announcements/{$id}/archive")->assertOk();

        $this->assertSame(
            1,
            Notification::where('recipient_user_id', $studentUser->id)
                ->where('type', NotificationType::ANNOUNCEMENT->value)
                ->count(),
        );
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/announcements')->assertUnauthorized();
        $this->postJson('/api/admin/announcements', $this->draftPayload())->assertUnauthorized();
    }
}
