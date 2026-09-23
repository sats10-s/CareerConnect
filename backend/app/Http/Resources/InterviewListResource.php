<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin row for GET /api/student/interviews - see
 * Api\Student\InterviewController::index. See InterviewResource for
 * the full detail payload.
 *
 * @mixin \App\Models\Interview
 */
class InterviewListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'round' => $this->round,
            'title' => $this->title,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'opportunity' => $this->whenLoaded('application', fn () => $this->opportunitySummary($this->application->applicable)),
        ];
    }
}
