<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;

/**
 * Admin detail response for GET /api/admin/ppo-records/{ppoRecord} -
 * thin subclass forcing the faculty/admin-only fields (student/
 * faculty/internship/history) unconditionally. Mirrors
 * AdminInternshipCompletionResource exactly, including its own
 * `use FormatsOpportunitySummary;` for the same private-trait-method
 * reason.
 */
class AdminPpoRecordResource extends PpoRecordResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        return array_merge($base, [
            'student' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('student') && $this->internshipAssignment->student ? [
                'id' => $this->internshipAssignment->student->id,
                'name' => $this->internshipAssignment->student->user?->name,
                'roll_number' => $this->internshipAssignment->student->roll_number,
                'department' => $this->internshipAssignment->student->department?->name,
                'year' => $this->internshipAssignment->student->year,
            ] : null),

            'faculty' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('faculty') && $this->internshipAssignment->faculty ? [
                'id' => $this->internshipAssignment->faculty->id,
                'name' => $this->internshipAssignment->faculty->user?->name,
            ] : null),

            'internship' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('internship')
                ? $this->opportunitySummary($this->internshipAssignment->internship)
                : null),

            'history' => $this->whenLoaded('statusHistory', fn () => PpoRecordStatusHistoryResource::collection($this->statusHistory)),
        ]);
    }
}
