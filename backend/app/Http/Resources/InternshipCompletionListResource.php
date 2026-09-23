<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/faculty/internship-completions and GET
 * /api/admin/internship-completions - one shared class, mirroring
 * InternshipDocumentListResource's own precedent of a single flat
 * shape for every role's list endpoint.
 *
 * @mixin \App\Models\InternshipCompletion
 */
class InternshipCompletionListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'completion_date' => $this->completion_date?->toDateString(),
            'outcome' => $this->outcome?->value,
            'status' => $this->status?->value,
            'finalized_at' => $this->finalized_at?->toIso8601String(),

            'student' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('student') && $this->internshipAssignment->student ? [
                'id' => $this->internshipAssignment->student->id,
                'name' => $this->internshipAssignment->student->user?->name,
                'roll_number' => $this->internshipAssignment->student->roll_number,
            ] : null),

            'faculty' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('faculty') && $this->internshipAssignment->faculty ? [
                'id' => $this->internshipAssignment->faculty->id,
                'name' => $this->internshipAssignment->faculty->user?->name,
            ] : null),

            'internship' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('internship')
                ? $this->opportunitySummary($this->internshipAssignment->internship)
                : null),
        ];
    }
}
