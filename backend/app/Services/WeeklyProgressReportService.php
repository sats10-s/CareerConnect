<?php

namespace App\Services;

use App\Enums\InternshipAssignmentStatus;
use App\Enums\WeeklyReportStatus;
use App\Events\WeeklyReportApproved;
use App\Events\WeeklyReportChangesRequested;
use App\Events\WeeklyReportSubmitted;
use App\Exceptions\WeeklyProgressReportException;
use App\Models\InternshipAssignment;
use App\Models\User;
use App\Models\WeeklyProgressReport;
use App\Models\WeeklyProgressReportStatusHistory;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single place every weekly-progress-report write goes through:
 * creating a draft, updating a draft, submitting it, and every faculty
 * review action (start review / approve / request changes). Each
 * method is atomic (its own DB::transaction()) and writes exactly one
 * paired WeeklyProgressReportStatusHistory row per lifecycle
 * transition - callers (Api\Student\WeeklyProgressReportController,
 * Api\Faculty\WeeklyProgressReportController) never touch
 * `weekly_progress_reports`/`weekly_progress_report_status_history`
 * directly, and never duplicate the transition rules enforced here.
 *
 * Never duplicates App\Services\InternshipAssignmentService or
 * App\Services\AttendanceService - the assignment's own lifecycle
 * (ASSIGNED/ACTIVE/COMPLETED/CANCELLED) is read here, never written;
 * creating/updating/submitting/reviewing a report never mutates the
 * assignment, the underlying application, or the internship
 * opportunity, and internship_assignment_id (and, transitively,
 * student_id/faculty_id) is always DERIVED from the InternshipAssignment
 * instance the caller resolved and had authorized - this service never
 * accepts or trusts a raw client-supplied student_id/faculty_id/
 * internship_id/company_id.
 *
 * "Internship period" is anchored to the assignment's own
 * started_at/ended_at, exactly as in AttendanceService - the
 * Internship model itself has no start/end date columns of its own.
 */
class WeeklyProgressReportService
{
    /**
     * Creates a new DRAFT report and its initial (null -> DRAFT)
     * history row, atomically. Only ever callable while
     * assertAssignmentAcceptsNewReports() allows it - see that
     * method's docblock for the exact ASSIGNED/ACTIVE/COMPLETED/
     * CANCELLED rule.
     */
    public function createDraft(InternshipAssignment $assignment, User $actingUser, array $data): WeeklyProgressReport
    {
        $this->assertAssignmentAcceptsNewReports($assignment);

        $weekNumber = (int) $data['week_number'];
        $weekStart = Carbon::parse($data['week_start_date'])->startOfDay();
        $weekEnd = Carbon::parse($data['week_end_date'])->startOfDay();

        $this->assertWeekIsValid($assignment, $weekNumber, $weekStart, $weekEnd);

        if (WeeklyProgressReport::where('internship_assignment_id', $assignment->id)->where('week_number', $weekNumber)->exists()) {
            throw new WeeklyProgressReportException('A weekly report for this week has already been created.');
        }

        try {
            return DB::transaction(function () use ($assignment, $actingUser, $weekNumber, $weekStart, $weekEnd, $data) {
                $report = WeeklyProgressReport::create([
                    'internship_assignment_id' => $assignment->id,
                    'week_number' => $weekNumber,
                    'week_start_date' => $weekStart,
                    'week_end_date' => $weekEnd,
                    'work_done' => $data['work_done'],
                    'learning_outcomes' => $data['learning_outcomes'] ?? null,
                    'challenges' => $data['challenges'] ?? null,
                    'next_week_plan' => $data['next_week_plan'] ?? null,
                    'status' => WeeklyReportStatus::DRAFT,
                ]);

                $this->recordHistory($report, null, WeeklyReportStatus::DRAFT, $actingUser, null);

                return $report;
            });
        } catch (QueryException $e) {
            // Safety net for a race between the exists() check above and
            // this insert - the DB-level unique constraint on
            // weekly_progress_reports.(internship_assignment_id,
            // week_number) is the actual source of truth; this just
            // turns its violation into the same clean business error
            // rather than a raw 500. Same pattern as
            // AttendanceService::markAttendance().
            if ($e->getCode() === '23000') {
                throw new WeeklyProgressReportException('A weekly report for this week has already been created.');
            }

            throw $e;
        }
    }

    /**
     * Updates an existing report's own content. `internship_assignment_id`
     * and `week_number` can never be changed through this method (they
     * are simply not accepted in $data - see
     * UpdateWeeklyProgressReportRequest, which does not define fields
     * for either) - per the module spec's "week_number cannot change
     * after creation" / "internship_assignment_id cannot change".
     * `week_start_date`/`week_end_date` MAY be updated, re-validated
     * against the same 7-day-week and internship-period rules
     * createDraft() enforces.
     *
     * Only allowed while the report's own status
     * isStudentEditable() (DRAFT or CHANGES_REQUESTED) and the
     * assignment is not terminal - see assertAssignmentNotTerminal().
     */
    public function updateDraft(WeeklyProgressReport $report, array $data, User $actingUser): WeeklyProgressReport
    {
        $this->assertAssignmentNotTerminal($report->internshipAssignment);

        if (! $report->status->isStudentEditable()) {
            throw new WeeklyProgressReportException(sprintf(
                'This report cannot be edited in its current status (%s).',
                $report->status->value,
            ));
        }

        $weekStart = array_key_exists('week_start_date', $data)
            ? Carbon::parse($data['week_start_date'])->startOfDay()
            : $report->week_start_date->copy();
        $weekEnd = array_key_exists('week_end_date', $data)
            ? Carbon::parse($data['week_end_date'])->startOfDay()
            : $report->week_end_date->copy();

        $this->assertWeekIsValid($report->internshipAssignment, $report->week_number, $weekStart, $weekEnd);

        return DB::transaction(function () use ($report, $data, $weekStart, $weekEnd) {
            $report->update([
                'week_start_date' => $weekStart,
                'week_end_date' => $weekEnd,
                'work_done' => array_key_exists('work_done', $data) ? $data['work_done'] : $report->work_done,
                'learning_outcomes' => array_key_exists('learning_outcomes', $data) ? $data['learning_outcomes'] : $report->learning_outcomes,
                'challenges' => array_key_exists('challenges', $data) ? $data['challenges'] : $report->challenges,
                'next_week_plan' => array_key_exists('next_week_plan', $data) ? $data['next_week_plan'] : $report->next_week_plan,
            ]);

            // A content edit is not a lifecycle transition - no history
            // row here; see recordHistory()'s callers for what does
            // create one.
            return $report->fresh();
        });
    }

    /**
     * Submits a DRAFT or CHANGES_REQUESTED report. Requires the
     * assignment to be ACTIVE right now (stricter than
     * assertAssignmentAcceptsNewReports() - see the module spec's own
     * "cannot submit until assignment is ACTIVE"), a valid DRAFT/
     * CHANGES_REQUESTED -> SUBMITTED transition, and non-empty required
     * content (assertContentComplete()) - "do not allow an empty
     * report submission".
     */
    public function submit(WeeklyProgressReport $report, User $actingUser): WeeklyProgressReport
    {
        $this->assertAssignmentActiveForSubmission($report->internshipAssignment);

        if (! $report->status->canTransitionTo(WeeklyReportStatus::SUBMITTED)) {
            throw new WeeklyProgressReportException(sprintf(
                'A report can only be submitted from DRAFT or CHANGES_REQUESTED (this report is %s).',
                $report->status->value,
            ));
        }

        $this->assertContentComplete($report);

        return DB::transaction(function () use ($report, $actingUser) {
            $from = $report->status;

            $report->update([
                'status' => WeeklyReportStatus::SUBMITTED,
                'student_submitted_at' => now(),
            ]);

            $this->recordHistory($report, $from, WeeklyReportStatus::SUBMITTED, $actingUser, null);
            event(new WeeklyReportSubmitted($report, $actingUser));

            return $report->fresh();
        });
    }

    /**
     * SUBMITTED -> UNDER_REVIEW. No remarks are expected for this
     * action - see transition()'s docblock for why any pre-existing
     * faculty_review_remarks is left untouched here.
     */
    public function startReview(WeeklyProgressReport $report, User $actingUser): WeeklyProgressReport
    {
        return $this->transition($report, WeeklyReportStatus::UNDER_REVIEW, $actingUser, null);
    }

    /**
     * UNDER_REVIEW -> APPROVED (terminal - see
     * WeeklyReportStatus::isTerminal()). $remarks is optional here,
     * unlike requestChanges().
     */
    public function approve(WeeklyProgressReport $report, User $actingUser, ?string $remarks): WeeklyProgressReport
    {
        return $this->transition($report, WeeklyReportStatus::APPROVED, $actingUser, $remarks);
    }

    /**
     * UNDER_REVIEW -> CHANGES_REQUESTED. $remarks is REQUIRED and must
     * be meaningful (non-blank) - see transition()'s own check - per
     * the module spec's "CHANGES_REQUESTED requires meaningful faculty
     * remarks".
     */
    public function requestChanges(WeeklyProgressReport $report, User $actingUser, string $remarks): WeeklyProgressReport
    {
        return $this->transition($report, WeeklyReportStatus::CHANGES_REQUESTED, $actingUser, $remarks);
    }

    /**
     * Shared implementation for every faculty review action -
     * startReview()/approve()/requestChanges(). Never modifies the
     * student's own report content (work_done/learning_outcomes/
     * challenges/next_week_plan/week_start_date/week_end_date/
     * week_number are all left completely untouched) - per the module
     * spec's "Do not modify student's original report content".
     */
    private function transition(WeeklyProgressReport $report, WeeklyReportStatus $to, User $actingUser, ?string $remarks): WeeklyProgressReport
    {
        $this->assertAssignmentNotTerminal($report->internshipAssignment);

        if (! $report->status->canTransitionTo($to)) {
            throw new WeeklyProgressReportException(sprintf(
                'This report cannot move from %s to %s.',
                $report->status->value,
                $to->value,
            ));
        }

        if ($to === WeeklyReportStatus::CHANGES_REQUESTED && trim((string) $remarks) === '') {
            throw new WeeklyProgressReportException('Requesting changes requires meaningful review remarks.');
        }

        return DB::transaction(function () use ($report, $to, $actingUser, $remarks) {
            $from = $report->status;

            $updates = [
                'status' => $to,
                'faculty_reviewed_at' => now(),
            ];

            // Only overwrite faculty_review_remarks when THIS action
            // actually supplied some - startReview() passes null and
            // must never blank out remarks a previous review round
            // left behind.
            if ($remarks !== null) {
                $updates['faculty_review_remarks'] = $remarks;
            }

            $report->update($updates);

            $this->recordHistory($report, $from, $to, $actingUser, $remarks);

            match ($to) {
                WeeklyReportStatus::APPROVED => event(new WeeklyReportApproved($report, $actingUser)),
                WeeklyReportStatus::CHANGES_REQUESTED => event(new WeeklyReportChangesRequested($report, $actingUser, $remarks)),
                default => null,
            };

            return $report->fresh();
        });
    }

    /**
     * Governs createDraft()/updateDraft(): ACTIVE always allows it;
     * ASSIGNED allows it only once the internship has actually started
     * (started_at set) - which, in this system, never actually occurs
     * today, since activate() is the only thing that ever sets
     * started_at and it does so in the same write that moves the
     * assignment to ACTIVE. This still follows the module spec's
     * literal "ASSIGNED: may prepare draft if internship has started"
     * rule faithfully rather than collapsing it into "ACTIVE only", in
     * case a future change to InternshipAssignmentService ever
     * introduces a started_at while still ASSIGNED. COMPLETED/CANCELLED
     * always reject - "no new reports" for both.
     */
    private function assertAssignmentAcceptsNewReports(InternshipAssignment $assignment): void
    {
        if ($assignment->status === InternshipAssignmentStatus::ACTIVE) {
            return;
        }

        if ($assignment->status === InternshipAssignmentStatus::ASSIGNED && $assignment->started_at !== null) {
            return;
        }

        throw new WeeklyProgressReportException(sprintf(
            'A weekly report cannot be prepared for this internship assignment (status: %s).',
            $assignment->status->value,
        ));
    }

    /**
     * Governs submit(): stricter than assertAssignmentAcceptsNewReports() -
     * the module spec explicitly says "cannot submit until assignment
     * is ACTIVE", with no ASSIGNED exception at all.
     */
    private function assertAssignmentActiveForSubmission(InternshipAssignment $assignment): void
    {
        if ($assignment->status !== InternshipAssignmentStatus::ACTIVE) {
            throw new WeeklyProgressReportException(sprintf(
                'A weekly report can only be submitted while the internship assignment is active (this assignment is %s).',
                $assignment->status->value,
            ));
        }
    }

    /**
     * Governs updateDraft() and every faculty review action: "existing
     * reports remain readable, no modification" for both COMPLETED and
     * CANCELLED - no exception, unlike AttendanceService's admin-
     * correction-after-completion carve-out (this module has no admin
     * write endpoint at all - see the module spec's "do not add
     * unnecessary admin write privileges").
     */
    private function assertAssignmentNotTerminal(InternshipAssignment $assignment): void
    {
        if ($assignment->status->isTerminal()) {
            throw new WeeklyProgressReportException(sprintf(
                'This report can no longer be modified (the internship assignment is %s).',
                $assignment->status->value,
            ));
        }
    }

    /**
     * week_number >= 1; week_end_date must be exactly week_start_date +
     * 6 days (a genuine 7-day week - the module spec's own preferred
     * default, since this codebase has no other established weekly
     * convention); the week must not start in the future ("do not
     * allow a report whose week has not started yet"); and the week
     * must not fall ENTIRELY outside the assignment's own
     * started_at/ended_at period (partial overlap is allowed - only a
     * week with zero overlap is rejected, per the module spec's literal
     * "entirely outside the internship period").
     */
    private function assertWeekIsValid(InternshipAssignment $assignment, int $weekNumber, Carbon $weekStart, Carbon $weekEnd): void
    {
        if ($weekNumber < 1) {
            throw new WeeklyProgressReportException('week_number must be at least 1.');
        }

        if (! $weekEnd->isSameDay($weekStart->copy()->addDays(6))) {
            throw new WeeklyProgressReportException('A weekly report must cover exactly a 7-day period (week_end_date must be week_start_date + 6 days).');
        }

        if ($weekStart->gt(Carbon::today())) {
            throw new WeeklyProgressReportException('A weekly report cannot be created for a week that has not started yet.');
        }

        if ($assignment->started_at === null) {
            throw new WeeklyProgressReportException('This internship assignment has not started yet; a weekly report cannot be prepared.');
        }

        $periodStart = $assignment->started_at->copy()->startOfDay();

        if ($weekEnd->lt($periodStart)) {
            throw new WeeklyProgressReportException(sprintf(
                'This week falls entirely before the internship start date (%s).',
                $periodStart->toDateString(),
            ));
        }

        if ($assignment->ended_at !== null) {
            $periodEnd = $assignment->ended_at->copy()->startOfDay();

            if ($weekStart->gt($periodEnd)) {
                throw new WeeklyProgressReportException(sprintf(
                    'This week falls entirely after the internship end date (%s).',
                    $periodEnd->toDateString(),
                ));
            }
        }
    }

    /**
     * "Do not allow an empty report submission" - at minimum:
     * week_number, dates, work_done. work_done can legitimately be
     * blanked back out during an in-progress DRAFT/CHANGES_REQUESTED
     * edit (see UpdateWeeklyProgressReportRequest), so this is the
     * load-bearing check, not merely a mirror of creation-time
     * validation.
     */
    private function assertContentComplete(WeeklyProgressReport $report): void
    {
        if ($report->week_number < 1
            || $report->week_start_date === null
            || $report->week_end_date === null
            || trim((string) $report->work_done) === ''
        ) {
            throw new WeeklyProgressReportException('This report cannot be submitted until week number, dates, and work done are filled in.');
        }
    }

    private function recordHistory(WeeklyProgressReport $report, ?WeeklyReportStatus $from, WeeklyReportStatus $to, User $actingUser, ?string $remarks): void
    {
        WeeklyProgressReportStatusHistory::create([
            'weekly_progress_report_id' => $report->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actingUser->id,
            'remarks' => $remarks,
        ]);
    }
}
