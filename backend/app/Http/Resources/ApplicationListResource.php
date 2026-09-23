<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight row for GET /api/student/applications - see
 * Api\Student\ApplicationController::index. Mirrors
 * OpportunityListResource's "thin list, richer detail" split; see
 * ApplicationResource for the full detail payload.
 *
 * @mixin \App\Models\Application
 */
class ApplicationListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'opportunity' => $this->opportunitySummary($this->applicable),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
