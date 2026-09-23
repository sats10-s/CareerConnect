<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/student/weekly-reports, GET
 * /api/faculty/weekly-reports and GET /api/admin/weekly-reports - one
 * shared class, mirroring AttendanceListResource's own precedent of a
 * single flat shape for every role's list endpoint (nothing here is
 * sensitive enough to need per-role gating: no review remarks, no
 * report content, no history).
 *
 * @mixin \App\Models\WeeklyProgressReport
 */
class WeeklyProgressReportListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'week_number' => $this->week_number,
            'week_start_date' => $this->week_start_date?->toDateString(),
            'week_end_date' => $this->week_end_date?->toDateString(),
            'status' => $this->status?->value,
            'student_submitted_at' => $this->student_submitted_at?->toIso8601String(),
            'faculty_reviewed_at' => $this->faculty_reviewed_at?->toIso8601String(),

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
