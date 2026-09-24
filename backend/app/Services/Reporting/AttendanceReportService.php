<?php

namespace App\Services\Reporting;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\InternshipAssignment;
use App\Services\Reporting\Concerns\AppliesReportFilters;

/**
 * Reports & Analytics module - internship attendance analytics.
 * Attendance is scoped exclusively through InternshipAssignment (see
 * App\Models\Attendance's docblock - it has no student_id/faculty_id
 * column of its own), so every query here is anchored on
 * InternshipAssignment and reaches its attendance() relation, never
 * queried starting from the `attendance` table directly.
 *
 * SEMANTIC DEFINITION - attendance percentage:
 *   present_days / (present_days + absent_days) * 100, rounded to 2
 *   decimals. LEAVE days are counted and reported on their own but are
 *   deliberately excluded from BOTH the numerator and the denominator
 *   of this percentage - an approved leave day is neither an
 *   attendance success nor a failure, so including it in the
 *   denominator would understate attendance for a student who has
 *   taken legitimate leave, and including it in the numerator would
 *   overstate it. An assignment with zero PRESENT/ABSENT marks (only
 *   LEAVE, or nothing marked yet) has a 0.0% attendance percentage by
 *   convention (never null/NaN), so every consumer can sort/threshold
 *   on it uniformly.
 */
class AttendanceReportService
{
    use AppliesReportFilters;

    /**
     * One row per internship assignment in scope, with its attendance
     * tallies and computed percentage.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function perAssignmentSummary(array $filters): array
    {
        $assignmentQuery = InternshipAssignment::query()->with(['student.user', 'student.department', 'student.batch', 'faculty.user']);
        $this->applyStudentRelationScope($assignmentQuery, $filters);

        if (! empty($filters['faculty_id'])) {
            $assignmentQuery->where('faculty_id', $filters['faculty_id']);
        }

        $assignments = $assignmentQuery->get();

        $tallies = $this->talliesByAssignment($assignments->pluck('id')->all(), $filters);

        return $assignments->map(function (InternshipAssignment $assignment) use ($tallies) {
            $tally = $tallies[$assignment->id] ?? ['PRESENT' => 0, 'ABSENT' => 0, 'LEAVE' => 0];
            $present = $tally['PRESENT'];
            $absent = $tally['ABSENT'];
            $leave = $tally['LEAVE'];
            $markedForRate = $present + $absent;

            return [
                'internship_assignment_id' => $assignment->id,
                'student_id' => $assignment->student_id,
                'student_name' => $assignment->student?->user?->name,
                'roll_number' => $assignment->student?->roll_number,
                'department' => $assignment->student?->department?->name,
                'batch' => $assignment->student?->batch?->name,
                'faculty_name' => $assignment->faculty?->user?->name,
                'present_days' => $present,
                'absent_days' => $absent,
                'leave_days' => $leave,
                'total_marked_days' => $present + $absent + $leave,
                'attendance_percentage' => $markedForRate > 0 ? round(($present / $markedForRate) * 100, 2) : 0.0,
            ];
        })->all();
    }

    /**
     * Every assignment in scope whose attendance percentage is below
     * the given threshold (a validated 0-100 value - see
     * App\Http\Requests\Reports\AttendanceReportFilterRequest). Reuses
     * perAssignmentSummary() rather than a second aggregate query, so
     * the "below threshold" list can never disagree with the
     * percentage the summary report shows for the same assignment.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function belowThreshold(array $filters, float $thresholdPercent): array
    {
        return array_values(array_filter(
            $this->perAssignmentSummary($filters),
            fn (array $row) => $row['attendance_percentage'] < $thresholdPercent,
        ));
    }

    /**
     * A single present/absent/leave tally query for every assignment
     * id at once, grouped in PHP by assignment - avoids one query per
     * assignment (N+1) for what would otherwise be a per-row
     * aggregate.
     *
     * @param  array<int, int>  $assignmentIds
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, int>>
     */
    private function talliesByAssignment(array $assignmentIds, array $filters): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $query = Attendance::query()->whereIn('internship_assignment_id', $assignmentIds);
        $this->applyDateRange($query, 'attendance_date', $filters);

        $rows = $query
            ->selectRaw('internship_assignment_id, status, COUNT(*) as c')
            ->groupBy('internship_assignment_id', 'status')
            ->get();

        $tallies = [];

        foreach ($rows as $row) {
            $status = $row->status instanceof \BackedEnum ? $row->status->value : $row->status;
            $tallies[$row->internship_assignment_id][$status] = (int) $row->c;
        }

        foreach ($tallies as $assignmentId => $byStatus) {
            foreach (AttendanceStatus::cases() as $status) {
                $tallies[$assignmentId][$status->value] ??= 0;
            }
        }

        return $tallies;
    }
}
