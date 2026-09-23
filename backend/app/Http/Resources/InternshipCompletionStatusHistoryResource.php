<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for an InternshipCompletion's `history` block - see
 * InternshipCompletionResource. Mirrors
 * InternshipDocumentStatusHistoryResource exactly.
 *
 * @mixin \App\Models\InternshipCompletionStatusHistory
 */
class InternshipCompletionStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ] : null),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
