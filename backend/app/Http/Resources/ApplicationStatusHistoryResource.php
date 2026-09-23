<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an application's audit trail - see
 * App\Models\ApplicationStatusHistory. Always rendered oldest-first by
 * the caller (see ApplicationResource/AdminApplicationResource and the
 * controllers' ->load() calls that order the statusHistory relation).
 *
 * @mixin \App\Models\ApplicationStatusHistory
 */
class ApplicationStatusHistoryResource extends JsonResource
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
