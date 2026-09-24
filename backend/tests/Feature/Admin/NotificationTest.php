<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/admin/notifications, GET
 * /api/admin/notifications/unread-count, PATCH
 * /api/admin/notifications/{notification}/read, POST
 * /api/admin/notifications/read-all - see
 * Api\Admin\NotificationController. Reachable by PLACEMENT_ADMIN and
 * SUPER_ADMIN (module spec section 5), NOT by FACULTY. These are the
 * admin's OWN notifications (e.g. important system workflow events -
 * spec section 9) - App\Policies\NotificationPolicy deliberately has no
 * admin bypass anywhere, so even a SUPER_ADMIN cannot read or mark read
 * an ordinary user's notification through this surface either.
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

    public function test_a_placement_admin_can_list_their_own_notifications(): void
    {
        $admin = $this->makeAdmin();
        $this->makeNotification($admin->id);
        $this->makeNotification($admin->id);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/notifications')->assertOk();

        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_a_super_admin_can_list_their_own_notifications(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $this->makeNotification($superAdmin->id);

        Sanctum::actingAs($superAdmin, ['*']);
        $response = $this->getJson('/api/admin/notifications')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_unread_count_is_correct(): void
    {
        $admin = $this->makeAdmin();
        $this->makeNotification($admin->id, read: true);
        $this->makeNotification($admin->id, read: false);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/notifications/unread-count')->assertOk();

        $this->assertSame(1, $response->json('data.unread_count'));
    }

    public function test_an_admin_can_mark_their_own_notification_read(): void
    {
        $admin = $this->makeAdmin();
        $notification = $this->makeNotification($admin->id);

        Sanctum::actingAs($admin, ['*']);
        $this->patchJson("/api/admin/notifications/{$notification->id}/read")->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_an_admin_can_mark_all_of_their_notifications_read(): void
    {
        $admin = $this->makeAdmin();
        $this->makeNotification($admin->id);
        $this->makeNotification($admin->id);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->postJson('/api/admin/notifications/read-all')->assertOk();

        $this->assertSame(2, $response->json('data.updated'));
    }

    public function test_a_faculty_member_cannot_use_the_admin_notification_routes(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson('/api/admin/notifications')->assertForbidden();
    }

    public function test_a_student_cannot_use_the_admin_notification_routes(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson('/api/admin/notifications')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/notifications')->assertUnauthorized();
    }

    /**
     * The requirement that "Super Admin must not bypass ownership
     * accidentally for ordinary user notifications" - a SUPER_ADMIN
     * cannot view or mark read a student's notification through the
     * admin notification surface, exactly like any other user.
     */
    public function test_a_super_admin_cannot_view_another_users_notification_in_their_own_list(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $studentUser = $this->makeStudentUser();
        $this->makeNotification($studentUser->id);

        Sanctum::actingAs($superAdmin, ['*']);
        $response = $this->getJson('/api/admin/notifications')->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_a_super_admin_cannot_mark_another_users_notification_read(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $studentUser = $this->makeStudentUser();
        $othersNotification = $this->makeNotification($studentUser->id);

        Sanctum::actingAs($superAdmin, ['*']);
        $this->patchJson("/api/admin/notifications/{$othersNotification->id}/read")->assertForbidden();

        $this->assertNull($othersNotification->fresh()->read_at);
    }

    public function test_a_placement_admin_cannot_mark_another_users_notification_read(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = $this->makeAdmin();
        $othersNotification = $this->makeNotification($otherAdmin->id);

        Sanctum::actingAs($admin, ['*']);
        $this->patchJson("/api/admin/notifications/{$othersNotification->id}/read")->assertForbidden();

        $this->assertNull($othersNotification->fresh()->read_at);
    }
}
