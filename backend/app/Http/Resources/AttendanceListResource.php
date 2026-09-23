<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/student/attendance, GET /api/faculty/attendance
 * and GET /api/admin/attendance - one shared class, mirroring
 * InternshipAssignmentListResource's own precedent of a single flat
 * shape for every role's list endpoint (nothing here is sensitive
 * enough to need per-role gating: no marked_by identity, no raw
 * timestamps, no history).
 *
 * @mixin \App\Models\Attendance
 */
class AttendanceListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status?->value,
            'check_in_time' => $this->check_in_time,
            'check_out_time' => $this->check_out_time,

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
