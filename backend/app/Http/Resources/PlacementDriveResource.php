<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Models\Internship;
use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for GET /api/admin/placement-drives/{placementDrive}
 * AND GET /api/student/placement-drives/{placementDrive} (also every
 * admin write action's response - store/update/publish/close/cancel/
 * attachOpportunity). One shared class - see PlacementDriveListResource's
 * docblock for why this module gates admin-only blocks inline with
 * viewer role rather than a second Admin* class.
 *
 * `opportunities` (the light PlacementDriveOpportunityResource summary
 * - "opportunity summaries", per the module spec's student-detail
 * field list) is always included for both audiences. `jobs`/
 * `internships` (the FULL JobPostingResource/InternshipResource detail
 * payload - "use existing Opportunity Resources where appropriate")
 * are admin-only, alongside aggregate counts, creator/updater/
 * cancelled_by, and status_history - exactly the fields the module
 * spec says a student response must never expose (internal admin
 * notes, created_by, updated_by, cancellation actor IDs, internal
 * audit fields).
 *
 * `application_count`/`selected_count`/`interview_count` are set as
 * plain dynamic attributes by the controller before this resource
 * runs (see App\Http\Controllers\Concerns\ComputesPlacementDriveStats)
 * - drive statistics are always derived from the EXISTING applications/
 * interviews tables (via placement_drive_opportunities), never
 * duplicated or stored on this model.
 *
 * @mixin \App\Models\PlacementDrive
 */
class PlacementDriveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewerIsAdmin($request);

        $base = [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'drive_type' => $this->drive_type?->value,
            'venue' => $this->venue,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'application_deadline' => $this->application_deadline?->toIso8601String(),
            'status' => $this->status?->value,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company?->id,
                'name' => $this->company?->name,
                'industry' => $this->company?->industry,
                'logo_path' => $this->company?->logo_path,
                'status' => $this->company?->status?->value,
            ]),

            'opportunities' => $this->relationLoaded('opportunities')
                ? PlacementDriveOpportunityResource::collection($this->opportunities)
                : null,
        ];

        if (! $isAdmin) {
            return $base;
        }

        $attachments = $this->relationLoaded('opportunities') ? $this->opportunities : collect();

        return array_merge($base, [
            'jobs' => JobPostingResource::collection(
                $attachments->map(fn ($row) => $row->opportunity)->filter(fn ($o) => $o instanceof JobPosting)->values()
            ),
            'internships' => InternshipResource::collection(
                $attachments->map(fn ($row) => $row->opportunity)->filter(fn ($o) => $o instanceof Internship)->values()
            ),

            'application_count' => (int) ($this->application_count ?? 0),
            'selected_count' => (int) ($this->selected_count ?? 0),
            'interview_count' => (int) ($this->interview_count ?? 0),

            'creator' => $this->relationLoaded('creator') && $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'updater' => $this->relationLoaded('updater') && $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null,
            'cancelled_by' => $this->relationLoaded('cancelledBy') && $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->name,
            ] : null,

            'status_history' => $this->relationLoaded('statusHistory')
                ? PlacementDriveStatusHistoryResource::collection($this->statusHistory)
                : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);
    }

    private function viewerIsAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
