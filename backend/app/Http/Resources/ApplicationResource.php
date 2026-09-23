<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for GET /api/student/applications/{application}
 * and the apply()/withdraw() responses - see
 * Api\Student\ApplicationController and
 * Api\Student\OpportunityController::applyToJob/applyToInternship.
 * `status_history` is only populated when the caller eager-loaded it
 * (ordered oldest -> newest - see the controllers' ->load() calls).
 * The `my_eligibility` sibling key on the detail endpoint's response
 * is merged in by the controller (mirroring
 * Api\Student\OpportunityController::showJob/showInternship), not
 * produced by this class.
 *
 * @mixin \App\Models\Application
 */
class ApplicationResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
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
