<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Admin detail response for GET /api/admin/weekly-reports/{report} -
 * thin subclass forcing the faculty/admin-only fields (student/
 * faculty/internship/history) unconditionally, since the admin
 * controller's context is always-admin by route middleware and doesn't
 * need WeeklyProgressReportResource's runtime viewerIsAdmin() check.
 * Mirrors AdminAttendanceResource exactly.
 */
class AdminWeeklyProgressReportResource extends WeeklyProgressReportResource
{
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

            'history' => $this->whenLoaded('statusHistory', fn () => WeeklyProgressReportStatusHistoryResource::collection($this->statusHistory)),
        ]);
    }
}
