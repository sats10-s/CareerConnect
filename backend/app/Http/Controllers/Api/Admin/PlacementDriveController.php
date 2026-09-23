<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PlacementDriveException;
use App\Http\Controllers\Concerns\ComputesPlacementDriveStats;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttachDriveOpportunityRequest;
use App\Http\Requests\Admin\CancelPlacementDriveRequest;
use App\Http\Requests\Admin\StorePlacementDriveRequest;
use App\Http\Requests\Admin\UpdatePlacementDriveRequest;
use App\Http\Resources\PlacementDriveListResource;
use App\Http\Resources\PlacementDriveOpportunityResource;
use App\Http\Resources\PlacementDriveResource;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\PlacementDrive;
use App\Models\PlacementDriveOpportunity;
use App\Services\PlacementDriveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin management of placement drives. Route-gated by
 * auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN (see routes/api.php);
 * PlacementDrivePolicy adds the object-level authorization layer on
 * top of that route gate, same pattern as Admin\ApplicationController/
 * Admin\InterviewController. Every lifecycle write goes through
 * App\Services\PlacementDriveService, never a direct ->update() here.
 */
class PlacementDriveController extends Controller
{
    use ComputesPlacementDriveStats;
    use FormatsApiResponse;

    public function __construct(
        private readonly PlacementDriveService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PlacementDrive::class);

        $query = PlacementDrive::query()->with('company')->withCount('opportunities');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if ($companyId = (int) $request->input('company_id')) {
            $query->where('company_id', $companyId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($driveType = $request->input('drive_type')) {
            $query->where('drive_type', $driveType);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('scheduled_at', '>=', Carbon::parse($dateFrom));
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('scheduled_at', '<=', Carbon::parse($dateTo));
        }

        if ($request->input('when') === 'upcoming') {
            $query->where('scheduled_at', '>=', now());
        } elseif ($request->input('when') === 'past') {
            $query->where('scheduled_at', '<', now());
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $drives = $query->latest()->paginate($perPage);

        $this->attachDriveStats($drives->items());

        return $this->success(
            data: [
                'items' => PlacementDriveListResource::collection($drives->items()),
                'meta' => [
                    'current_page' => $drives->currentPage(),
                    'per_page' => $drives->perPage(),
                    'total' => $drives->total(),
                    'last_page' => $drives->lastPage(),
                ],
            ],
            message: 'Placement drives retrieved successfully.',
        );
    }

    public function store(StorePlacementDriveRequest $request): JsonResponse
    {
        Gate::authorize('create', PlacementDrive::class);

        try {
            $drive = $this->service->create($request->validated(), $request->user());
        } catch (PlacementDriveException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($drive);
        $this->attachDriveStats([$drive], withInterviews: true);

        return $this->success(data: new PlacementDriveResource($drive), message: 'Placement drive created successfully.', status: 201);
    }

    public function show(PlacementDrive $placementDrive): JsonResponse
    {
        Gate::authorize('view', $placementDrive);

        $this->loadDetail($placementDrive);
        $this->attachDriveStats([$placementDrive], withInterviews: true);

        return $this->success(data: new PlacementDriveResource($placementDrive), message: 'Placement drive retrieved successfully.');
    }

    public function update(UpdatePlacementDriveRequest $request, PlacementDrive $placementDrive): JsonResponse
    {
        Gate::authorize('update', $placementDrive);

        try {
            $placementDrive = $this->service->update($placementDrive, $request->validated(), $request->user());
        } catch (PlacementDriveException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($placementDrive);
        $this->attachDriveStats([$placementDrive], withInterviews: true);

        return $this->success(data: new PlacementDriveResource($placementDrive), message: 'Placement drive updated successfully.');
    }

    public function publish(Request $request, PlacementDrive $placementDrive): JsonResponse
    {
        Gate::authorize('update', $placementDrive);

        try {
            $placementDrive = $this->service->publish($placementDrive, $request->user());
        } catch (PlacementDriveException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($placementDrive);
        $this->attachDriveStats([$placementDrive], withInterviews: true);

        return $this->success(data: new PlacementDriveResource($placementDrive), message: 'Placement drive published successfully.');
    }

    public function close(Request $request, PlacementDrive $placementDrive): JsonResponse
    {
        Gate::authorize('update', $placementDrive);

        try {
            $placementDrive = $this->service->close($placementDrive, $request->user());
        } catch (PlacementDriveException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($placementDrive);
        $this->attachDriveStats([$placementDrive], withInterviews: true);

        return $this->success(data: new PlacementDriveResource($placementDrive), message: 'Placement drive closed successfully.');
    }

    public function cancel(CancelPlacementDriveRequest $request, PlacementDrive $placementDrive): JsonResponse
    {
        Gate::authorize('update', $placementDrive);

        try {
            $placementDrive = $this->service->cancel($placementDrive, $request->user(), $request->validated('reason'));
        } catch (PlacementDriveException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($placementDrive);
        $this->attachDriveStats([$placementDrive], withInterviews: true);

        return $this->success(data: new PlacementDriveResource($placementDrive), message: 'Placement drive cancelled successfully.');
    }

    public function attachOpportunity(AttachDriveOpportunityRequest $request, PlacementDrive $placementDrive): JsonResponse
    {
        Gate::authorize('update', $placementDrive);

        try {
            $attachment = $this->service->attachOpportunity(
                $placementDrive,
                $request->validated('opportunity_type'),
                (int) $request->validated('opportunity_id'),
                $request->user(),
            );
        } catch (PlacementDriveException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $attachment->load('opportunity.company');

        return $this->success(data: new PlacementDriveOpportunityResource($attachment), message: 'Opportunity attached successfully.', status: 201);
    }

    public function detachOpportunity(Request $request, PlacementDrive $placementDrive, PlacementDriveOpportunity $opportunity): JsonResponse
    {
        Gate::authorize('update', $placementDrive);

        try {
            $this->service->detachOpportunity($placementDrive, $opportunity, $request->user());
        } catch (PlacementDriveException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(message: 'Opportunity detached successfully.');
    }

    /**
     * Eager loads everything PlacementDriveResource's admin view
     * needs, including the FULL eligibility graph JobPostingResource/
     * InternshipResource expect per attached opportunity (via
     * MorphTo::morphWith(), since JobPosting/Internship each need
     * their own relation set) - mirrors
     * Admin\JobPostingController::EAGER_LOAD exactly, just reached
     * through the polymorphic `opportunities.opportunity` path instead
     * of a direct relation.
     */
    private function loadDetail(PlacementDrive $placementDrive): void
    {
        $placementDrive->load([
            'company', 'creator', 'updater', 'cancelledBy',
            'opportunities' => fn ($q) => $q->with(['opportunity' => function ($morphTo) {
                $morphTo->morphWith([
                    JobPosting::class => ['company', 'creator', 'updater', 'eligibilityCriteria', 'eligibleDepartments.department', 'eligibleBatches.batch', 'eligibleYears', 'requiredSkills'],
                    Internship::class => ['company', 'creator', 'updater', 'eligibilityCriteria', 'eligibleDepartments.department', 'eligibleBatches.batch', 'eligibleYears', 'requiredSkills'],
                ]);
            }]),
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
        ]);
    }
}
