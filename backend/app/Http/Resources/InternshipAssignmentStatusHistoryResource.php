<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an internship assignment's append-only STATUS audit
 * trail - mirrors InterviewStatusHistoryResource/
 * PlacementDriveStatusHistoryResource exactly. Visible to BOTH the
 * admin and the assigned faculty member (see the module spec's
 * faculty-detail field list, which explicitly includes "assignment
 * history") - gating, where needed, happens one level up in
 * InternshipAssignmentResource, not here.
 *
 * @mixin \App\Models\InternshipAssignmentStatusHistory
 */
class InternshipAssignmentStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'remarks' => $this->remarks,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
