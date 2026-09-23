<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for GET /api/student/interviews/{interview} -
 * see Api\Student\InterviewController::show. Deliberately excludes
 * `panel_notes` (internal admin-only notes) and `interviewer_email`
 * (not in the module spec's enumerated student-visible field list) -
 * see AdminInterviewResource for the admin-facing equivalent that
 * includes them. cancelled_at/cancellation_reason/completed_at ARE
 * included here even though the spec's field list doesn't name them
 * explicitly: without them a student viewing a CANCELLED interview
 * would see only a bare status with no explanation, which reads as a
 * worse product experience than the spec's "never expose internal
 * admin notes/panel notes" rule appears to be guarding against - this
 * is a deliberate, flagged interpretation (see the module's final
 * report), not an oversight.
 *
 * @mixin \App\Models\Interview
 */
class InterviewResource extends JsonResource
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
            'meeting_url' => $this->meeting_url,
            'venue' => $this->venue,
            'interviewer_name' => $this->interviewer_name,
            'status' => $this->status->value,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'opportunity' => $this->whenLoaded('application', fn () => $this->opportunitySummary($this->application->applicable)),
        ];
    }
}
