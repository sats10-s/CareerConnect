<?php

namespace Tests\Feature\Faculty;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/faculty/notifications, GET
 * /api/faculty/notifications/unread-count, PATCH
 * /api/faculty/notifications/{notification}/read, POST
 * /api/faculty/notifications/read-all - see
 * Api\Faculty\NotificationController. Mirrors
 * Tests\Feature\Student\NotificationTest exactly, scoped to a faculty
 * user instead, plus the reverse cross-role isolation check (a student
 * cannot use the faculty routes).
 */
class NotificationTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
    }

    private function makeNotification(int $recipientUserId, bool $read = false, ?\DateTimeInterface $createdAt = null): Notification
    {
        $notification = Notification::create([
            'recipient_user_id' => $recipientUserId,
            'type' => NotificationType::SYSTEM,
            'title' => 'Test notification',
            'message' => 'A test notification.',
            'data' => ['x' => 1],
            'read_at' => $read ? now() : null,
        ]);

        if ($createdAt !== null) {
            $notification->forceFill(['created_at' => $createdAt])->save();
        }

        return $notification;
    }

    public function test_a_faculty_member_can_list_their_own_notifications(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeNotification($faculty->user->id);
        $this->makeNotification($faculty->user->id);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/notifications')->assertOk();

        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(2, $response->json('data.meta.total'));
    }

    public function test_notifications_are_returned_newest_first(): void
    {
        $faculty = $this->makeFaculty();
        $older = $this->makeNotification($faculty->user->id, createdAt: now()->subDays(2));
        $newer = $this->makeNotification($faculty->user->id, createdAt: now()->subMinutes(5));

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/notifications')->assertOk();

        $ids = array_column($response->json('data.items'), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_the_unread_filter_only_returns_unread_notifications(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeNotification($faculty->user->id, read: true);
        $unread = $this->makeNotification($faculty->user->id, read: false);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/notifications?status=unread')->assertOk();

        $ids = array_column($response->json('data.items'), 'id');
        $this->assertSame([$unread->id], $ids);
    }

    public function test_unread_count_is_correct(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeNotification($faculty->user->id, read: true);
        $this->makeNotification($faculty->user->id, read: false);
        $this->makeNotification($faculty->user->id, read: false);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/notifications/unread-count')->assertOk();

        $this->assertSame(2, $response->json('data.unread_count'));
    }

    public function test_a_faculty_member_can_mark_their_own_notification_read(): void
    {
        $faculty = $this->makeFaculty();
        $notification = $this->makeNotification($faculty->user->id);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->patchJson("/api/faculty/notifications/{$notification->id}/read")->assertOk();

        $this->assertNotNull($response->json('data.read_at'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_faculty_member_can_mark_all_of_their_notifications_read(): void
    {
        $faculty = $this->makeFaculty();
        $this->makeNotification($faculty->user->id);
        $this->makeNotification($faculty->user->id);
        $this->makeNotification($faculty->user->id, read: true);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->postJson('/api/faculty/notifications/read-all')->assertOk();

        $this->assertSame(2, $response->json('data.updated'));
        $this->assertSame(0, Notification::where('recipient_user_id', $faculty->user->id)->whereNull('read_at')->count());
    }

    /**
     * The core IDOR guard - a faculty member can never reach, nor mark
     * read, another user's notification just by changing the
     * {notification} id in the URL.
     */
    public function test_a_faculty_member_cannot_mark_another_users_notification_read(): void
    {
        $faculty = $this->makeFaculty();
        $otherFaculty = $this->makeFaculty();
        $othersNotification = $this->makeNotification($otherFaculty->user->id);

        Sanctum::actingAs($faculty->user, ['*']);
        $this->patchJson("/api/faculty/notifications/{$othersNotification->id}/read")->assertForbidden();

        $this->assertNull($othersNotification->fresh()->read_at);
    }

    public function test_a_faculty_members_notification_list_never_includes_another_users_notifications(): void
    {
        $faculty = $this->makeFaculty();
        $otherFaculty = $this->makeFaculty();
        $this->makeNotification($otherFaculty->user->id);

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/notifications')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/faculty/notifications')->assertUnauthorized();
        $this->getJson('/api/faculty/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/faculty/notifications/read-all')->assertUnauthorized();
    }

    public function test_a_student_cannot_use_the_faculty_notification_routes(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson('/api/faculty/notifications')->assertForbidden();
    }
}
