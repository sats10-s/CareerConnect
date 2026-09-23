<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One opportunity attached to a placement drive - a light, audience-
 * safe summary (id/type/title/status/company), reusing the same
 * FormatsOpportunitySummary trait Application/Interview resources
 * already use, rather than the full JobPostingResource/
 * InternshipResource detail payload. Used for:
 *  - the `opportunities` block on both PlacementDriveResource and
 *    PlacementDriveListResource (the "opportunity summaries" a
 *    student is meant to see - see the module spec's student-detail
 *    field list);
 *  - the attach-opportunity admin endpoint's own response
 *    (Api\Admin\PlacementDriveController::attachOpportunity).
 *
 * The admin detail view additionally gets the FULL JobPostingResource/
 * InternshipResource payload via PlacementDriveResource's admin-only
 * `jobs`/`internships` blocks - this class is deliberately not that.
 *
 * @mixin \App\Models\PlacementDriveOpportunity
 */
class PlacementDriveOpportunityResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'attachment_id' => $this->id,
            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunitySummary($this->opportunity)),
            'attached_at' => $this->created_at,
        ];
    }
}
