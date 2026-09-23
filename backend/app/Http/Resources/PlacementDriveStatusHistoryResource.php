<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a placement drive's audit trail - see
 * App\Models\PlacementDriveStatusHistory. Always rendered oldest-first
 * by the caller. Mirrors ApplicationStatusHistoryResource/
 * InterviewStatusHistoryResource exactly.
 *
 * @mixin \App\Models\PlacementDriveStatusHistory
 */
class PlacementDriveStatusHistoryResource extends JsonResource
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
