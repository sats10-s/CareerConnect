<?php

namespace App\Services\Reporting;

use App\Enums\InternshipAssignmentStatus;
use App\Enums\InternshipCompletionOutcome;
use App\Enums\InternshipCompletionStatus;
use App\Enums\PpoStatus;
use App\Models\InternshipAssignment;
use App\Models\InternshipCompletion;
use App\Models\PpoRecord;
use App\Services\Reporting\Concerns\AppliesReportFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reports & Analytics module - internship-side analytics, built
 * entirely from existing InternshipAssignment/InternshipCompletion/
 * PpoRecord data (module spec: no new tables, no Faculty Evaluation/
 * scoring - that is explicitly NOT part of this system). Every query
 * here scopes department/batch/year by joining through
 * InternshipAssignment -> Student, matching the codebase's existing,
 * repeatedly-documented convention that Attendance/WeeklyProgressReport/
 * InternshipDocument/InternshipCompletion/PpoRecord all have no
 * student_id/faculty_id column of their own - ownership is always
 * derived via internship_assignment_id.
 *
 * SEMANTIC DEFINITIONS:
 *  - "completion rate" = FINALIZED completions with outcome COMPLETED,
 *    divided by all FINALIZED completions (a DRAFT completion record
 *    is still an in-progress faculty assessment, not yet a verdict -
 *    it is excluded from the rate's denominator, mirroring
 *    InternshipCompletionStatus's own DRAFT/FINALIZED distinction).
 *  - "PPO offer rate" = PPO records created, divided by FINALIZED
 *    completions (a PPO is only ever recorded once an internship's
 *    completion has been finalized - see
 *    App\Services\InternshipCompletionService::createPpo()) - never
 *    divided by total assignments, which would understate the rate by
 *    counting internships that haven't finished yet.
 */
class InternshipReportService
{
    use AppliesReportFilters;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $assignmentQuery = InternshipAssignment::query();
        $this->applyStudentRelationScope($assignmentQuery, $filters);
        $this->applyDateRange($assignmentQuery, 'assigned_at', $filters);

        if (! empty($filters['faculty_id'])) {
            $assignmentQuery->where('faculty_id', $filters['faculty_id']);
        }

        $byStatus = [];

        foreach (InternshipAssignmentStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $assignmentQuery)->where('status', $status->value)->count();
        }

        $totalAssignments = array_sum($byStatus);

        $completionQuery = InternshipCompletion::query()
            ->whereHas('internshipAssignment', function (Builder $q) use ($filters) {
                $this->applyStudentRelationScope($q, $filters);

                if (! empty($filters['faculty_id'])) {
                    $q->where('faculty_id', $filters['faculty_id']);
                }
            });

        $finalizedCompletions = (clone $completionQuery)->where('status', InternshipCompletionStatus::FINALIZED->value);
        $finalizedCount = (clone $finalizedCompletions)->count();
        $completedCount = (clone $finalizedCompletions)->where('outcome', InternshipCompletionOutcome::COMPLETED->value)->count();
        $notCompletedCount = (clone $finalizedCompletions)->where('outcome', InternshipCompletionOutcome::NOT_COMPLETED->value)->count();

        $ppoQuery = PpoRecord::query()
            ->whereHas('internshipAssignment', function (Builder $q) use ($filters) {
                $this->applyStudentRelationScope($q, $filters);

                if (! empty($filters['faculty_id'])) {
                    $q->where('faculty_id', $filters['faculty_id']);
                }
            });

        $ppoOffered = (clone $ppoQuery)->count();
        $ppoAccepted = (clone $ppoQuery)->where('status', PpoStatus::ACCEPTED->value)->count();
        $ppoDeclined = (clone $ppoQuery)->where('status', PpoStatus::DECLINED->value)->count();

        return [
            'total_assignments' => $totalAssignments,
            'assignments_by_status' => $byStatus,
            'completions_finalized' => $finalizedCount,
            'completions_outcome_completed' => $completedCount,
            'completions_outcome_not_completed' => $notCompletedCount,
            'completion_rate' => $finalizedCount > 0 ? round(($completedCount / $finalizedCount) * 100, 2) : 0.0,
            'ppo_offered' => $ppoOffered,
            'ppo_accepted' => $ppoAccepted,
            'ppo_declined' => $ppoDeclined,
            'ppo_offer_rate' => $finalizedCount > 0 ? round(($ppoOffered / $finalizedCount) * 100, 2) : 0.0,
        ];
    }
}
