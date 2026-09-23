<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\PlacementDriveStatus;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlacementDriveListResource;
use App\Http\Resources\PlacementDriveResource;
use App\Models\Application;
use App\Models\Internship;
use App\Models\JobPosting;
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

        $data = (new PlacementDriveResource($placementDrive))->toArray($request);
        $data['opportunities'] = $this->withApplicationStatus($request, $placementDrive, $data['opportunities'] ?? []);

        return $this->success(data: $data, message: 'Placement drive retrieved successfully.');
    }

    /**
     * Merges the authenticated student's own application status onto
     * each attached-opportunity summary, where one exists - never a
     * second eligibility/filtering system, purely informational (see
     * the module spec's "application status where applicable").
     * Ownership is always derived from $request->user()->student,
     * never trusted from the request.
     *
     * @param array<int, array<string, mixed>> $opportunities
     * @return array<int, array<string, mixed>>
     */
    private function withApplicationStatus(Request $request, PlacementDrive $placementDrive, array $opportunities): array
    {
        $student = $request->user()->student;

        if (! $student) {
            return array_map(fn ($item) => $item + ['application_status' => null], $opportunities);
        }

        $pairs = $placementDrive->opportunities->map(fn ($row) => [
            'type' => $row->opportunity_type,
            'id' => $row->opportunity_id,
        ]);

        if ($pairs->isEmpty()) {
            return array_map(fn ($item) => $item + ['application_status' => null], $opportunities);
        }

        $applications = Application::query()
            ->where('student_id', $student->id)
            ->where(function ($query) use ($pairs) {
                foreach ($pairs as $pair) {
                    $query->orWhere(function ($inner) use ($pair) {
                        $inner->where('applicable_type', $pair['type'])->where('applicable_id', $pair['id']);
                    });
                }
            })
            ->get()
            ->keyBy(fn ($application) => $application->applicable_type.':'.$application->applicable_id);

        return array_map(function ($item) use ($applications) {
            $type = ($item['opportunity']['type'] ?? null) === 'internship' ? Internship::class : JobPosting::class;
            $key = $type.':'.($item['opportunity']['id'] ?? null);

            $item['application_status'] = $applications->get($key)?->status?->value;

            return $item;
        }, $opportunities);
    }
}
