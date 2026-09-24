<?php

namespace App\Services\Reporting;

use App\Enums\InternshipAssignmentStatus;
use App\Enums\InternshipDocumentStatus;
use App\Enums\WeeklyReportStatus;
use App\Models\Faculty;
use App\Models\InternshipDocument;
use App\Models\WeeklyProgressReport;
use App\Services\Reporting\Concerns\AppliesReportFilters;

/**
 * Reports & Analytics module - per-faculty workload analytics. Built
 * entirely from InternshipAssignment and the two review queues that
 * hang off it (WeeklyProgressReport, InternshipDocument) - NOT a
 * Faculty Evaluation/scoring report (explicitly out of scope for this
 * system - see this module's final report). "Workload" here means
 * volume (how many students, how many items awaiting this faculty
 * member's action), never a quality judgement.
 *
 * SEMANTIC DEFINITIONS:
 *  - assigned_students: distinct students across this faculty's
 *    InternshipAssignment rows, ANY status (a CANCELLED/COMPLETED
 *    assignment still counts as something this faculty member has
 *    coordinated) - active_students below is the narrower, current
 *    subset.
 *  - active_students: the same, restricted to ASSIGNED/ACTIVE
 *    assignments (InternshipAssignmentStatus - COMPLETED/CANCELLED
 *    excluded).
 *  - pending_weekly_reports: weekly reports on this faculty's
 *    assignments currently in SUBMITTED or UNDER_REVIEW - the two
 *    statuses that represent "waiting on this faculty member",
 *    per App\Enums\WeeklyReportStatus's lifecycle (DRAFT is still the
 *    student's own draft; CHANGES_REQUESTED/APPROVED are already
 *    faculty-actioned).
 *  - pending_documents: internship documents on this faculty's
 *    assignments currently in SUBMITTED or UNDER_REVIEW, mirroring the
 *    weekly-report rule above via
 *    App\Enums\InternshipDocumentStatus's identical shape.
 */
class FacultyReportService
{
    use AppliesReportFilters;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function workload(array $filters): array
    {
        $facultyQuery = Faculty::query()->with('user', 'department');

        if (! empty($filters['department_id'])) {
            $facultyQuery->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['faculty_id'])) {
            $facultyQuery->where('id', $filters['faculty_id']);
        }

        return $facultyQuery->get()->map(function (Faculty $faculty) {
            $assignments = $faculty->internshipAssignments();

            $activeStatuses = [InternshipAssignmentStatus::ASSIGNED->value, InternshipAssignmentStatus::ACTIVE->value];

            return [
                'faculty_id' => $faculty->id,
                'faculty_name' => $faculty->user?->name,
                'department' => $faculty->department?->name,
                'assigned_students' => (clone $assignments)->distinct('student_id')->count('student_id'),
                'active_students' => (clone $assignments)->whereIn('status', $activeStatuses)->distinct('student_id')->count('student_id'),
                'pending_weekly_reports' => WeeklyProgressReport::query()
                    ->whereIn('internship_assignment_id', (clone $assignments)->pluck('id'))
                    ->whereIn('status', [WeeklyReportStatus::SUBMITTED->value, WeeklyReportStatus::UNDER_REVIEW->value])
                    ->count(),
                'pending_documents' => InternshipDocument::query()
                    ->whereIn('internship_assignment_id', (clone $assignments)->pluck('id'))
                    ->whereIn('status', [InternshipDocumentStatus::SUBMITTED->value, InternshipDocumentStatus::UNDER_REVIEW->value])
                    ->count(),
            ];
        })->all();
    }
}
