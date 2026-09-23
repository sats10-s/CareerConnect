<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats the plain array returned by
 * App\Services\AttendanceService::getAssignmentAttendanceSummary()
 * (never client-supplied counts/percentages - see that method's
 * docblock) for GET /api/student/internship-assignments/{assignment}/attendance
 * and GET /api/faculty/internship-assignments/{assignment}/attendance.
 *
 * @mixin array{internship_assignment_id: int, total_days: int, present_days: int, absent_days: int, leave_days: int, attendance_percentage: float, last_attendance_date: ?string, current_streak: int}
 */
class AttendanceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'internship_assignment_id' => $this->resource['internship_assignment_id'],
            'total_days' => $this->resource['total_days'],
            'present_days' => $this->resource['present_days'],
            'absent_days' => $this->resource['absent_days'],
            'leave_days' => $this->resource['leave_days'],
            'attendance_percentage' => $this->resource['attendance_percentage'],
            'last_attendance_date' => $this->resource['last_attendance_date'],
            'current_streak' => $this->resource['current_streak'],
        ];
    }
}
