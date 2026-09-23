<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/admin/internship-assignments AND GET
 * /api/faculty/internship-assignments - one shared class. Unlike
 * PlacementDriveListResource, nothing here is admin-only: every field
 * the module spec asks for in the admin list (section 7) is also
 * asked for in the faculty list (section 13), so there is no viewer
 * gating to do.
 *
 * @mixin \App\Models\InternshipAssignment
 */
class InternshipAssignmentListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),

            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'name' => $this->student->user?->name,
                'roll_number' => $this->student->roll_number,
                'department' => $this->student->department?->name,
                'year' => $this->student->year,
            ] : null),

            'faculty' => $this->whenLoaded('faculty', fn () => $this->faculty ? [
                'id' => $this->faculty->id,
                'name' => $this->faculty->user?->name,
            ] : null),

            'internship' => $this->whenLoaded('internship', fn () => $this->opportunitySummary($this->internship)),
        ];
    }
}
