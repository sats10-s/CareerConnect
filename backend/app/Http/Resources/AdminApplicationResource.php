<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for GET /api/admin/applications/{application}
 * and the updateStatus() response - see Api\Admin\ApplicationController.
 * The `resume` block mirrors StudentProfileResource's own resume field
 * exactly (metadata only - original filename/size/uploaded_at - never
 * the storage path), reusing the existing private-resume system
 * rather than building a new one, per the module spec's "do not expose
 * private file paths or storage internals".
 *
 * @mixin \App\Models\Application
 */
class AdminApplicationResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,

            // $this->student is only ever touched inside this closure so
            // whenLoaded() can actually gate it - accessing it before the
            // whenLoaded() call would force a lazy load even when the
            // caller didn't eager-load it (see Api\Admin\ApplicationController's
            // ->load() calls, which always include 'student.*').
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->user?->name,
                'email' => $this->student->user?->email,
                'roll_number' => $this->student->roll_number,
                'department' => $this->student->department?->name,
                'batch' => $this->student->batch?->name,
                'year' => $this->student->year,
                'cgpa' => $this->student->cgpa === null ? null : (float) $this->student->cgpa,
                'backlogs' => $this->student->backlogs,
                'resume' => $this->student->resume_path ? [
                    'original_name' => $this->student->resume_original_name,
                    'size' => $this->student->resume_size,
                    'uploaded_at' => $this->student->resume_uploaded_at?->toIso8601String(),
                ] : null,
            ]),

            'opportunity' => $this->opportunitySummary($this->applicable),

            'applied_at' => $this->applied_at?->toIso8601String(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'selected_at' => $this->selected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,

            'status_history' => $this->whenLoaded(
                'statusHistory',
                fn () => ApplicationStatusHistoryResource::collection($this->statusHistory)
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
