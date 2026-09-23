<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\PlacementDriveStatus;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlacementDriveListResource;
use App\Http\Resources\PlacementDriveResource;
use App\Models\Application;
use App\Models\PlacementDrive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Published placement drives, browsable by any student. PRODUCT RULE -
 * do not weaken this: index() lists every PUBLISHED drive to every
 * student, full stop, exactly like
 * Api\Student\OpportunityController::index's own published-opportunity
 * listing - there is no second eligibility-filtering system here.
 * Existing opportunity eligibility remains entirely
 * App\Services\OpportunityEligibilityService's concern, evaluated per
 * opportunity, never per drive.
 */
class PlacementDriveController extends Controller
{
    use FormatsApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = PlacementDrive::query()
            ->where('status', PlacementDriveStatus::PUBLISHED)
            ->with('company')
            ->withCount('opportunities');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if ($driveType = $request->input('drive_type')) {
            $query->where('drive_type', $driveType);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $drives = $query->orderBy('scheduled_at')->paginate($perPage);

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

    public function show(Request $request, PlacementDrive $placementDrive): JsonResponse
    {
        Gate::authorize('view', $placementDrive);

        $placementDrive->load([
            'company',
            'opportunities.opportunity.company',
        ]);

        $this->attachApplicationStatuses($request, $placementDrive);

        return $this->success(data: new PlacementDriveResource($placementDrive), message: 'Placement drive retrieved successfully.');
    }

    /**
     * Sets `application_status` as a plain dynamic attribute on each
     * already-loaded PlacementDriveOpportunity row, BEFORE
     * PlacementDriveResource/PlacementDriveOpportunityResource ever
     * serialize the drive - the same "compute once, read as an
     * attribute" pattern
     * App\Http\Controllers\Concerns\ComputesPlacementDriveStats uses
     * for the drive's own application/selected/interview counts (see
     * that trait's docblock). This is what fixes the type boundary:
     * earlier this method ran AFTER
     * (new PlacementDriveResource($placementDrive))->toArray($request),
     * mutating what it assumed was a plain PHP array - but toArray()
     * does not recursively resolve nested resources, so
     * $data['opportunities'] was actually still an
     * AnonymousResourceCollection object, not an array. Operating on
     * the underlying Eloquent rows first, and letting
     * PlacementDriveResource serialize normally afterwards, avoids
     * that boundary entirely and needs no manual array reconstruction.
     *
     * Never a second eligibility/filtering system - purely
     * informational (see the module spec's "application status where
     * applicable"). Ownership is always derived from
     * $request->user()->student, never trusted from the request.
     */
    private function attachApplicationStatuses(Request $request, PlacementDrive $placementDrive): void
    {
        $opportunities = $placementDrive->opportunities;

        if ($opportunities->isEmpty()) {
            return;
        }

        $student = $request->user()->student;

        if (! $student) {
            $opportunities->each(fn ($row) => $row->setAttribute('application_status', null));

            return;
        }

        $applications = Application::query()
            ->where('student_id', $student->id)
            ->where(function ($query) use ($opportunities) {
                foreach ($opportunities as $row) {
                    $query->orWhere(function ($inner) use ($row) {
                        $inner->where('applicable_type', $row->opportunity_type)->where('applicable_id', $row->opportunity_id);
                    });
                }
            })
            ->get()
            ->keyBy(fn ($application) => $application->applicable_type.':'.$application->applicable_id);

        $opportunities->each(function ($row) use ($applications) {
            $key = $row->opportunity_type.':'.$row->opportunity_id;
            $row->setAttribute('application_status', $applications->get($key)?->status?->value);
        });
    }
}
