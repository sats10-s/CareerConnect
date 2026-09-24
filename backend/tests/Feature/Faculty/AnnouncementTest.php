<?php

namespace Tests\Feature\Faculty;

use App\Enums\NotificationType;
use App\Models\Announcement;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/faculty/announcements, GET
 * /api/faculty/announcements/{announcement} - see
 * Api\Faculty\AnnouncementController. Mirrors
 * Tests\Feature\Student\AnnouncementTest exactly, scoped to a faculty
 * user instead (FACULTY/ALL audiences).
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

    private function makeAnnouncement(array $overrides = []): Announcement
    {
        $admin = $overrides['creator'] ?? $this->makeAdmin();

        return Announcement::create(array_merge([
            'title' => 'Test Announcement',
            'body' => 'Announcement body.',
            'category' => 'GENERAL',
            'audience' => 'ALL',
            'status' => 'PUBLISHED',
            'publish_at' => now()->subHour(),
            'expires_at' => null,
            'created_by' => $admin->id,
        ], array_diff_key($overrides, array_flip(['creator']))));
    }

    public function test_a_faculty_member_sees_published_announcements_addressed_to_faculty(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeAnnouncement(['audience' => 'FACULTY']);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/announcements')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_a_faculty_member_sees_announcements_addressed_to_all(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeAnnouncement(['audience' => 'ALL']);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/announcements')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_a_faculty_member_does_not_see_announcements_addressed_to_students(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeAnnouncement(['audience' => 'STUDENTS']);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_a_draft_announcement_is_never_shown(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeAnnouncement(['audience' => 'FACULTY', 'status' => 'DRAFT']);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_a_future_scheduled_announcement_is_hidden(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeAnnouncement(['audience' => 'FACULTY', 'publish_at' => now()->addDays(3)]);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_an_expired_announcement_is_hidden(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeAnnouncement(['audience' => 'FACULTY', 'publish_at' => now()->subDays(5), 'expires_at' => now()->subDay()]);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_an_archived_announcement_is_hidden(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeAnnouncement(['audience' => 'FACULTY', 'status' => 'ARCHIVED']);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_a_faculty_member_cannot_view_a_student_only_announcement_directly(): void
    {
        $faculty = $this->makeFaculty();
        $announcement = $this->makeAnnouncement(['audience' => 'STUDENTS']);

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson("/api/faculty/announcements/{$announcement->id}")->assertForbidden();
    }

    public function test_a_faculty_member_can_view_a_visible_announcement_directly(): void
    {
        $faculty = $this->makeFaculty();
        $announcement = $this->makeAnnouncement(['audience' => 'FACULTY']);

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson("/api/faculty/announcements/{$announcement->id}")->assertOk();
    }

    public function test_a_faculty_member_cannot_create_edit_publish_or_archive_announcements(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->postJson('/api/faculty/announcements', ['title' => 'x'])->assertStatus(405);
    }

    public function test_a_faculty_member_receives_a_notification_when_a_relevant_announcement_is_published(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', [
            'title' => 'Faculty Meeting',
            'body' => 'Please attend.',
            'category' => 'GENERAL',
            'audience' => 'FACULTY',
        ])->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $faculty->user->id,
            'type' => NotificationType::ANNOUNCEMENT->value,
        ]);
    }

    public function test_repeated_faculty_fetches_never_duplicate_the_announcement_notification(): void
    {
        $admin = $this->makeAdmin();
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', [
            'title' => 'Faculty Meeting',
            'body' => 'Please attend.',
            'category' => 'GENERAL',
            'audience' => 'FACULTY',
        ])->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson('/api/faculty/announcements')->assertOk();
        $this->getJson("/api/faculty/announcements/{$id}")->assertOk();

        $this->assertSame(
            1,
            Notification::where('recipient_user_id', $faculty->user->id)
                ->where('type', NotificationType::ANNOUNCEMENT->value)
                ->count(),
        );
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/faculty/announcements')->assertUnauthorized();
    }
}
