<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/admin/interviews - see
 * Api\Admin\InterviewController::index. Adds student identity and the
 * parent application's own status on top of what
 * InterviewListResource exposes to the owning student - deliberately
 * a separate class, same reasoning as AdminApplicationListResource vs
 * ApplicationListResource.
 *
 * @mixin \App\Models\Interview
 */
class AdminInterviewListResource extends JsonResource
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

            'student' => $this->whenLoaded('application', fn () => $this->application->student ? [
                'id' => $this->application->student->id,
                'name' => $this->application->student->user?->name,
                'roll_number' => $this->application->student->roll_number,
                'department' => $this->application->student->department?->name,
            ] : null),

            'opportunity' => $this->whenLoaded('application', fn () => $this->opportunitySummary($this->application->applicable)),

            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->id,
                'status' => $this->application->status->value,
            ]),
        ];
    }
}
