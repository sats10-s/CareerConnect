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
 * `application_status` is the requesting student's own application
 * status for this attached opportunity, where one exists - see
 * Api\Student\PlacementDriveController::attachApplicationStatuses(),
 * which sets it as a plain dynamic attribute on each
 * PlacementDriveOpportunity row BEFORE this resource ever resolves,
 * the same "compute once, read as an attribute" pattern
 * App\Http\Controllers\Concerns\ComputesPlacementDriveStats already
 * uses for application_count/selected_count/interview_count on the
 * drive itself. Gated by array_key_exists() rather than isset(),
 * since the attribute is deliberately set to null for "no
 * application" - isset() would treat that null the same as "never
 * set" and silently drop the key. It is never set on the admin
 * surface, so it never appears there.
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
            'application_status' => $this->when(
                array_key_exists('application_status', $this->resource->getAttributes()),
                fn () => $this->application_status,
            ),
        ];
    }
}
