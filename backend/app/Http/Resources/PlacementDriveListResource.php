<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/admin/placement-drives AND GET
 * /api/student/placement-drives - one shared class, not a separate
 * Admin/Student pair of classes like the Interview module used. The task's own
 * resource list for this module names exactly three classes
 * (PlacementDriveResource, PlacementDriveListResource,
 * PlacementDriveOpportunityResource) - admin-only fields
 * (application_count/selected_count/created_at) are gated inline by
 * viewer role instead of living in a second class, the same way
 * Api\Student\OpportunityController already reuses JobPostingResource
 * directly for students rather than having its own class.
 *
 * application_count/selected_count are set as plain dynamic
 * attributes by the controller before this resource ever runs (see
 * App\Http\Controllers\Concerns\ComputesPlacementDriveStats) - never
 * computed per-row here, which is what keeps a page of drives to a
 * fixed handful of queries instead of N+1. opportunity_count comes
 * from a standard ->withCount('opportunities') on the query.
 *
 * @mixin \App\Models\PlacementDrive
 */
class PlacementDriveListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'title' => $this->title,
            'drive_type' => $this->drive_type?->value,
            'status' => $this->status?->value,
            'venue' => $this->venue,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'application_deadline' => $this->application_deadline?->toIso8601String(),

            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company?->id,
                'name' => $this->company?->name,
                'logo_path' => $this->company?->logo_path,
            ]),

            'opportunity_count' => $this->opportunities_count === null ? null : (int) $this->opportunities_count,
        ];

        if (! $this->viewerIsAdmin($request)) {
            return $base;
        }

        return array_merge($base, [
            'application_count' => (int) ($this->application_count ?? 0),
            'selected_count' => (int) ($this->selected_count ?? 0),
            'created_at' => $this->created_at,
        ]);
    }

    private function viewerIsAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
