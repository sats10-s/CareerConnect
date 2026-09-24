<?php

namespace Tests\Feature\Student;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/student/notifications, GET
 * /api/student/notifications/unread-count, PATCH
 * /api/student/notifications/{notification}/read, POST
 * /api/student/notifications/read-all - see
 * Api\Student\NotificationController. Notifications are never created
 * through an HTTP endpoint (there is none - they are only ever
 * produced by App\Listeners\* / App\Services\AnnouncementService), so
 * every fixture here is a direct Notification::create() call, mirroring
 * this codebase's own precedent for read-only-surface tests around a
 * table nothing user-facing writes to directly (see
 * ExcelImportRollbackTest::test_only_a_confirmed_batch_can_be_rolled_back()'s
 * direct ExcelImportBatch::create()).
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

    public function test_a_student_can_list_their_own_notifications(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeNotification($studentUser->id);
        $this->makeNotification($studentUser->id);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/notifications')->assertOk();

        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(2, $response->json('data.meta.total'));
    }

    public function test_notifications_are_returned_newest_first(): void
    {
        $studentUser = $this->makeStudentUser();
        $older = $this->makeNotification($studentUser->id, createdAt: now()->subDays(2));
        $newer = $this->makeNotification($studentUser->id, createdAt: now()->subMinutes(5));

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/notifications')->assertOk();

        $ids = array_column($response->json('data.items'), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_notifications_are_paginated(): void
    {
        $studentUser = $this->makeStudentUser();

        for ($i = 0; $i < 3; $i++) {
            $this->makeNotification($studentUser->id);
        }

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/notifications?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.meta.total'));
        $this->assertSame(2, $response->json('data.meta.last_page'));
    }

    public function test_the_unread_filter_only_returns_unread_notifications(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeNotification($studentUser->id, read: true);
        $unread = $this->makeNotification($studentUser->id, read: false);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/notifications?status=unread')->assertOk();

        $ids = array_column($response->json('data.items'), 'id');
        $this->assertSame([$unread->id], $ids);
    }

    public function test_unread_count_is_correct(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeNotification($studentUser->id, read: true);
        $this->makeNotification($studentUser->id, read: false);
        $this->makeNotification($studentUser->id, read: false);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/notifications/unread-count')->assertOk();

        $this->assertSame(2, $response->json('data.unread_count'));
    }

    public function test_a_student_can_mark_their_own_notification_read(): void
    {
        $studentUser = $this->makeStudentUser();
        $notification = $this->makeNotification($studentUser->id);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->patchJson("/api/student/notifications/{$notification->id}/read")->assertOk();

        $this->assertNotNull($response->json('data.read_at'));
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'read_at' => $notification->fresh()->read_at]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_marking_an_already_read_notification_read_is_idempotent(): void
    {
        $studentUser = $this->makeStudentUser();
        $notification = $this->makeNotification($studentUser->id, read: true);
        $originalReadAt = $notification->read_at;

        Sanctum::actingAs($studentUser, ['*']);
        $this->patchJson("/api/student/notifications/{$notification->id}/read")->assertOk();

        $this->assertTrue($notification->fresh()->read_at->equalTo($originalReadAt));
    }

    public function test_a_student_can_mark_all_of_their_notifications_read(): void
    {
        $studentUser = $this->makeStudentUser();
        $this->makeNotification($studentUser->id);
        $this->makeNotification($studentUser->id);
        $this->makeNotification($studentUser->id, read: true);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->postJson('/api/student/notifications/read-all')->assertOk();

        $this->assertSame(2, $response->json('data.updated'));
        $this->assertSame(0, Notification::where('recipient_user_id', $studentUser->id)->whereNull('read_at')->count());
    }

    /**
     * The core IDOR guard - a student can never reach, nor mark read,
     * another user's notification just by changing the {notification}
     * id in the URL.
     */
    public function test_a_student_cannot_mark_another_users_notification_read(): void
    {
        $studentUser = $this->makeStudentUser();
        $otherStudentUser = $this->makeStudentUser();
        $othersNotification = $this->makeNotification($otherStudentUser->id);

        Sanctum::actingAs($studentUser, ['*']);
        $this->patchJson("/api/student/notifications/{$othersNotification->id}/read")->assertForbidden();

        $this->assertNull($othersNotification->fresh()->read_at);
    }

    public function test_marking_all_read_never_touches_another_users_notifications(): void
    {
        $studentUser = $this->makeStudentUser();
        $otherStudentUser = $this->makeStudentUser();
        $othersNotification = $this->makeNotification($otherStudentUser->id);

        Sanctum::actingAs($studentUser, ['*']);
        $this->postJson('/api/student/notifications/read-all')->assertOk();

        $this->assertNull($othersNotification->fresh()->read_at);
    }

    public function test_a_students_notification_list_never_includes_another_users_notifications(): void
    {
        $studentUser = $this->makeStudentUser();
        $otherStudentUser = $this->makeStudentUser();
        $this->makeNotification($otherStudentUser->id);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/notifications')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/student/notifications')->assertUnauthorized();
        $this->getJson('/api/student/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/student/notifications/read-all')->assertUnauthorized();
    }

    public function test_a_faculty_member_cannot_use_the_student_notification_routes(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson('/api/student/notifications')->assertForbidden();
    }
}
