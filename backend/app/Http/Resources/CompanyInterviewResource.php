<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An interview as a recruiter sees it (/api/company/interviews). Same
 * fields as AdminInterviewResource except the student block, which carries
 * no email or phone (see CompanyApplicantResource).
 *
 * @mixin \App\Models\Interview
 */
class CompanyInterviewResource extends JsonResource
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
            'interviewer_email' => $this->interviewer_email,
            'panel_notes' => $this->panel_notes,
            'status' => $this->status->value,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'student' => $this->whenLoaded('application', fn () => $this->application->student ? [
                'id' => $this->application->student->id,
                'name' => $this->application->student->user?->name,
                'roll_number' => $this->application->student->roll_number,
                'department' => $this->application->student->department?->name,
                'batch' => $this->application->student->batch?->name,
                'year' => $this->application->student->year,
            ] : null),
            'opportunity' => $this->whenLoaded('application', fn () => $this->opportunitySummary($this->application->applicable)),
            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->id,
                'status' => $this->application->status->value,
            ]),
            'status_history' => $this->whenLoaded(
                'statusHistory',
                fn () => InterviewStatusHistoryResource::collection($this->statusHistory)
            ),
            'created_at' => $this->created_at,
        ];
    }
}
