<?php

namespace Tests\Feature\Student;

use App\Enums\NotificationType;
use App\Models\Announcement;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/student/announcements, GET
 * /api/student/announcements/{announcement} - see
 * Api\Student\AnnouncementController. A student's feed is always scoped
 * to PUBLISHED, publish_at-reached, not-yet-expired announcements
 * addressed to STUDENTS or ALL (App\Services\AnnouncementService::activeFor()) -
 * a student can neither create, edit, publish, nor archive anything
 * here (those endpoints do not exist on this controller at all).
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

    public function test_a_student_sees_published_announcements_addressed_to_students(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeAnnouncement(['audience' => 'STUDENTS']);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/announcements')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_a_student_sees_announcements_addressed_to_all(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeAnnouncement(['audience' => 'ALL']);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/announcements')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_a_student_does_not_see_announcements_addressed_to_faculty(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeAnnouncement(['audience' => 'FACULTY']);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_a_draft_announcement_is_never_shown(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeAnnouncement(['audience' => 'STUDENTS', 'status' => 'DRAFT']);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_a_future_scheduled_announcement_is_hidden(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeAnnouncement(['audience' => 'STUDENTS', 'publish_at' => now()->addDays(3)]);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_an_expired_announcement_is_hidden(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeAnnouncement(['audience' => 'STUDENTS', 'publish_at' => now()->subDays(5), 'expires_at' => now()->subDay()]);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_an_archived_announcement_is_hidden(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeAnnouncement(['audience' => 'STUDENTS', 'status' => 'ARCHIVED']);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/announcements')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    /**
     * The direct-by-id IDOR guard - a student cannot fetch a
     * FACULTY-only or DRAFT announcement just by knowing its id.
     */
    public function test_a_student_cannot_view_a_faculty_only_announcement_directly(): void
    {
        $studentUser = $this->makeStudentUser();
        $announcement = $this->makeAnnouncement(['audience' => 'FACULTY']);

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson("/api/student/announcements/{$announcement->id}")->assertForbidden();
    }

    public function test_a_student_cannot_view_a_draft_announcement_directly(): void
    {
        $studentUser = $this->makeStudentUser();
        $announcement = $this->makeAnnouncement(['audience' => 'STUDENTS', 'status' => 'DRAFT']);

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson("/api/student/announcements/{$announcement->id}")->assertForbidden();
    }

    public function test_a_student_can_view_a_visible_announcement_directly(): void
    {
        $studentUser = $this->makeStudentUser();
        $announcement = $this->makeAnnouncement(['audience' => 'STUDENTS']);

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson("/api/student/announcements/{$announcement->id}")->assertOk();
    }

    public function test_a_student_cannot_create_edit_publish_or_archive_announcements(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $this->postJson('/api/student/announcements', ['title' => 'x'])->assertStatus(405);
    }

    public function test_a_student_receives_a_notification_when_a_relevant_announcement_is_published(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', [
            'title' => 'Important Update',
            'body' => 'Please read.',
            'category' => 'IMPORTANT',
            'audience' => 'STUDENTS',
        ])->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $studentUser->id,
            'type' => NotificationType::ANNOUNCEMENT->value,
        ]);
    }

    public function test_repeated_student_fetches_never_duplicate_the_announcement_notification(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($admin, ['*']);
        $id = $this->postJson('/api/admin/announcements', [
            'title' => 'Important Update',
            'body' => 'Please read.',
            'category' => 'IMPORTANT',
            'audience' => 'STUDENTS',
        ])->json('data.id');
        $this->postJson("/api/admin/announcements/{$id}/publish")->assertOk();

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson('/api/student/announcements')->assertOk();
        $this->getJson("/api/student/announcements/{$id}")->assertOk();
        $this->getJson('/api/student/announcements')->assertOk();

        $this->assertSame(
            1,
            Notification::where('recipient_user_id', $studentUser->id)
                ->where('type', NotificationType::ANNOUNCEMENT->value)
                ->count(),
        );
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/student/announcements')->assertUnauthorized();
    }
}
