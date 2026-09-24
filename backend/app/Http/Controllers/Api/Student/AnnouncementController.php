<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\AnnouncementFilterRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A student's own read-only view of announcements - list (always
 * scoped to PUBLISHED, publish_at-reached, not-yet-expired, and
 * addressed to STUDENTS or ALL - see
 * App\Services\AnnouncementService::activeFor()) and single-announcement
 * detail. A student can neither create, edit, publish, nor archive
 * anything here - those abilities do not exist on this controller at
 * all, matching the module spec's "Students ... cannot create/edit/
 * publish/archive".
 */
class AnnouncementController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AnnouncementService $announcementService) {}

    public function index(AnnouncementFilterRequest $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $announcements = $this->announcementService->activeFor($request->user(), $request->validated(), $perPage);

        return $this->success(
            data: [
                'items' => AnnouncementResource::collection($announcements->items()),
                'meta' => [
                    'current_page' => $announcements->currentPage(),
                    'per_page' => $announcements->perPage(),
                    'total' => $announcements->total(),
                    'last_page' => $announcements->lastPage(),
                ],
            ],
            message: 'Announcements retrieved successfully.',
        );
    }

    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('view', $announcement);

        return $this->success(data: new AnnouncementResource($announcement->load('creator')), message: 'Announcement retrieved successfully.');
    }
}
