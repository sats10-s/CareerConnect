<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\AnnouncementException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\AnnouncementFilterRequest;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin/super-admin management of announcements: create
 * draft, edit draft, publish, archive, view, list/filter/search -
 * every ability the module spec's section 7 lists for Admin/Super
 * Admin. Unlike Api\Student\AnnouncementController /
 * Api\Faculty\AnnouncementController, index()/show() here are built
 * directly off the Announcement model with NO active()/visibleTo()
 * scoping - an admin is explicitly allowed to see drafts, archived
 * announcements, and every audience (see
 * App\Policies\AnnouncementPolicy::viewAny()/view()), which is exactly
 * what managing them requires. Status changes always go through
 * App\Services\AnnouncementService, never a direct ->update() here.
 */
class AnnouncementController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AnnouncementService $announcementService) {}

    public function index(AnnouncementFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Announcement::class);

        $query = Announcement::query()->with(['creator', 'updater']);

        if ($category = $request->validated('category')) {
            $query->where('category', $category);
        }

        if ($audience = $request->validated('audience')) {
            $query->where('audience', $audience);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $announcements = $query->latest()->paginate($perPage);

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

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        Gate::authorize('create', Announcement::class);

        $announcement = $this->announcementService->createDraft($request->validated(), $request->user());

        return $this->success(data: new AnnouncementResource($announcement->load('creator')), message: 'Announcement draft created successfully.', status: 201);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        Gate::authorize('view', $announcement);

        return $this->success(data: new AnnouncementResource($announcement->load(['creator', 'updater'])), message: 'Announcement retrieved successfully.');
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('update', $announcement);

        try {
            $announcement = $this->announcementService->updateDraft($announcement, $request->validated(), $request->user());
        } catch (AnnouncementException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(data: new AnnouncementResource($announcement->load(['creator', 'updater'])), message: 'Announcement updated successfully.');
    }

    public function publish(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('publish', $announcement);

        try {
            $announcement = $this->announcementService->publish($announcement, $request->user());
        } catch (AnnouncementException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(data: new AnnouncementResource($announcement->load(['creator', 'updater'])), message: 'Announcement published successfully.');
    }

    public function archive(Request $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('archive', $announcement);

        try {
            $announcement = $this->announcementService->archive($announcement, $request->user());
        } catch (AnnouncementException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(data: new AnnouncementResource($announcement->load(['creator', 'updater'])), message: 'Announcement archived successfully.');
    }
}
