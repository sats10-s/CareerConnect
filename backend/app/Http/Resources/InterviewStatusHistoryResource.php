<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an interview's audit trail - see
 * App\Models\InterviewStatusHistory. Always rendered oldest-first by
 * the caller. Mirrors ApplicationStatusHistoryResource exactly.
 *
 * @mixin \App\Models\InterviewStatusHistory
 */
class InterviewStatusHistoryResource extends JsonResource
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
