<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\NotificationFilterRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A faculty member's own in-app notifications - list, unread count,
 * mark one read, mark all read. See Api\Student\NotificationController's
 * docblock for the shared IDOR-prevention pattern (identical here,
 * just scoped to the authenticated faculty user instead).
 */
class NotificationController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(NotificationFilterRequest $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $notifications = $this->notificationService->paginateForUser($request->user(), $request->validated(), $perPage);

        return $this->success(
            data: [
                'items' => NotificationResource::collection($notifications->items()),
                'meta' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ],
            ],
            message: 'Notifications retrieved successfully.',
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(
            data: ['unread_count' => $this->notificationService->unreadCount($request->user())],
            message: 'Unread count retrieved successfully.',
        );
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        Gate::authorize('markRead', $notification);

        $notification = $this->notificationService->markRead($notification);

        return $this->success(data: new NotificationResource($notification), message: 'Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->notificationService->markAllRead($request->user());

        return $this->success(data: ['updated' => $updated], message: 'All notifications marked as read.');
    }
}
