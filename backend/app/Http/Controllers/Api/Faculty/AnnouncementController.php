<?php

namespace App\Http\Controllers\Api\Faculty;

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
 * A faculty member's own read-only view of announcements - mirrors
 * Api\Student\AnnouncementController exactly, scoped to FACULTY/ALL
 * audiences instead. Faculty cannot manage announcements at all -
 * matching the module spec's explicit "do not allow Faculty to manage
 * announcements unless existing RBAC explicitly grants it" (it does
 * not - see App\Policies\AnnouncementPolicy).
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
