<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\InternshipAssignmentStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\AttendanceHistory;
use App\Models\InternshipAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single place every attendance write goes through: marking a new
 * day's attendance and correcting an existing record. Each method is
 * atomic (its own DB::transaction()) and writes exactly one paired
 * AttendanceHistory row per creation/change - callers (
 * Api\Faculty\AttendanceController, Api\Admin\AttendanceController)
 * never touch `attendance`/`attendance_history` directly.
 *
 * Never duplicates App\Services\InternshipAssignmentService - the
 * assignment's own lifecycle (ASSIGNED/ACTIVE/COMPLETED/CANCELLED) is
 * read here, never written; marking/updating attendance never mutates
 * the assignment, the underlying application, or the internship
 * opportunity.
 *
 * internship_assignment_id (and, transitively, student_id/faculty_id)
 * is always DERIVED from the InternshipAssignment instance the caller
 * resolved and had authorized - see Api\Faculty\AttendanceController,
 * which resolves the assignment from the route and checks
 * Gate::authorize('create'/'update', ...) before ever calling this
 * service - this service never accepts or trusts a raw client-supplied
 * student_id/faculty_id/internship_assignment_id.
 *
 * Summaries are computed per-ASSIGNMENT (getAssignmentAttendanceSummary())
 * rather than as two separate "student" and "assignment" methods - a
 * summary is inherently scoped to one internship assignment in this
 * schema (see the module spec's own section 12, "For an internship
 * assignment return..."), and Api\Student\AttendanceController simply
 * calls this same method after resolving and authorizing the
 * student's own assignment, so a second, merely-delegating
 * getStudentAttendanceSummary() would only duplicate this one without
 * adding behavior.
 */
class AttendanceService
{
    /**
     * Marks a new attendance record for `$assignment` and its initial
     * (null -> status) history row, atomically. Only ever callable for
     * an ACTIVE assignment - see assertAssignmentIsActive() - which by
     * construction also rules out marking attendance before the
     * internship has actually started (an assignment's `started_at` is
     * only ever set on activation) as well as after it has been
     * completed or cancelled, satisfying the module spec's "no new
     * attendance can be marked after assignment completion" / "no new
     * attendance" for cancelled assignments in one single check.
     */
    public function markAttendance(InternshipAssignment $assignment, User $actingUser, array $data): Attendance
    {
        $this->assertAssignmentIsActive($assignment);

        $status = $this->normalizeStatus($data['status']);
        $date = Carbon::parse($data['attendance_date'])->startOfDay();

        $this->assertDateWithinPeriod($assignment, $date);
        $this->assertNotFutureDate($date);

        [$checkIn, $checkOut] = $this->assertTimeConsistency($status, $data['check_in_time'] ?? null, $data['check_out_time'] ?? null);

        $remarks = $data['remarks'] ?? null;

        if (Attendance::where('internship_assignment_id', $assignment->id)->whereDate('attendance_date', $date)->exists()) {
            throw new AttendanceException('Attendance for this date has already been marked.');
        }

        try {
            return DB::transaction(function () use ($assignment, $actingUser, $status, $date, $checkIn, $checkOut, $remarks) {
                $attendance = Attendance::create([
                    'internship_assignment_id' => $assignment->id,
                    'attendance_date' => $date,
                    'status' => $status,
                    'check_in_time' => $checkIn,
                    'check_out_time' => $checkOut,
                    'remarks' => $remarks,
                    'marked_by' => $actingUser->id,
                ]);

                $this->recordHistory($attendance, null, $status, null, $checkIn, null, $checkOut, null, $remarks, $actingUser);

                return $attendance;
            });
        } catch (QueryException $e) {
            // Safety net for a race between the exists() check above and
            // this insert - the DB-level unique constraint on
            // attendance.(internship_assignment_id, attendance_date) is
            // the actual source of truth; this just turns its violation
            // into the same clean business error rather than a raw 500.
            // Same pattern as InternshipAssignmentService::create().
            if ($e->getCode() === '23000') {
                throw new AttendanceException('Attendance for this date has already been marked.');
            }

            throw $e;
        }
    }

    /**
     * Corrects an existing attendance record. `$data` may contain any
     * subset of status/check_in_time/check_out_time/remarks - fields
     * not present keep their current value. `internship_assignment_id`,
     * the derived student/internship, and `attendance_date` can never
     * be changed through this method (they are simply not accepted in
     * $data - see UpdateAttendanceRequest, which does not define
     * fields for them at all) - see the module spec's "these cannot be
     * changed through an ordinary attendance update".
     *
     * $restrictToActiveAssignment mirrors markAttendance()'s own ACTIVE-
     * only rule for an ordinary faculty correction; an admin correction
     * passes false, since "admin correction may remain available"
     * after the assignment is completed or cancelled per the module
     * spec's section 21 - Api\Admin\AttendanceController is the only
     * caller that passes false.
     */
    public function updateAttendance(Attendance $attendance, array $data, User $actingUser, bool $restrictToActiveAssignment = true): Attendance
    {
        if ($restrictToActiveAssignment) {
            $this->assertAssignmentIsActive($attendance->internshipAssignment);
        }

        $oldStatus = $attendance->status;
        $oldCheckIn = $attendance->check_in_time;
        $oldCheckOut = $attendance->check_out_time;
        $oldRemarks = $attendance->remarks;

        $explicitCheckIn = array_key_exists('check_in_time', $data);
        $explicitCheckOut = array_key_exists('check_out_time', $data);

        $newStatus = array_key_exists('status', $data) ? $this->normalizeStatus($data['status']) : $oldStatus;
        $newCheckIn = $explicitCheckIn ? $data['check_in_time'] : $oldCheckIn;
        $newCheckOut = $explicitCheckOut ? $data['check_out_time'] : $oldCheckOut;
        $newRemarks = array_key_exists('remarks', $data) ? $data['remarks'] : $oldRemarks;

        // Re-derive time consistency from the EFFECTIVE resulting state,
        // not just the fields this particular request happened to
        // include - e.g. changing status to ABSENT without explicitly
        // clearing check_in_time still results in a consistent record,
        // never a stale PRESENT-shaped time left dangling on an ABSENT
        // row. That means a bare {status: "ABSENT"} request - which
        // carries over the OLD, still-non-null PRESENT times because
        // neither time field was touched - must silently clear them
        // here rather than let assertTimeConsistency() reject them as
        // a contradiction; the client is never forced to redundantly
        // null the times out itself just to change the status. Only an
        // EXPLICITLY supplied non-null time alongside a status that
        // disallows times (e.g. {status: "ABSENT", check_in_time:
        // "09:00"}) is a genuine error, which assertTimeConsistency()
        // below still catches.
        if (! $newStatus->allowsTimes() && ! $explicitCheckIn && ! $explicitCheckOut) {
            $newCheckIn = null;
            $newCheckOut = null;
        }

        [$newCheckIn, $newCheckOut] = $this->assertTimeConsistency($newStatus, $newCheckIn, $newCheckOut);

        $unchanged = $newStatus === $oldStatus
            && $this->normalizeTimeForComparison($newCheckIn) === $this->normalizeTimeForComparison($oldCheckIn)
            && $this->normalizeTimeForComparison($newCheckOut) === $this->normalizeTimeForComparison($oldCheckOut)
            && $newRemarks === $oldRemarks;

        if ($unchanged) {
            return $attendance;
        }

        return DB::transaction(function () use ($attendance, $actingUser, $oldStatus, $newStatus, $oldCheckIn, $newCheckIn, $oldCheckOut, $newCheckOut, $oldRemarks, $newRemarks) {
            $attendance->update([
                'status' => $newStatus,
                'check_in_time' => $newCheckIn,
                'check_out_time' => $newCheckOut,
                'remarks' => $newRemarks,
            ]);

            $this->recordHistory($attendance, $oldStatus, $newStatus, $oldCheckIn, $newCheckIn, $oldCheckOut, $newCheckOut, $oldRemarks, $newRemarks, $actingUser);

            return $attendance->fresh();
        });
    }

    /**
     * Server-computed attendance summary for one internship assignment
     * - never trusts client-supplied counts/percentages. `total_days`
     * and `total_marked_days` are the same number in this
     * implementation: an attendance row only ever exists for a day
     * that was actually marked (there is no separate calendar of
     * "expected working days" anywhere in this schema, and inventing
     * one would mean inventing an academic rule the module spec
     * explicitly warns against) - see this class's own docblock.
     *
     * `current_streak` counts the most recent consecutive PRESENT
     * records by marked order, stopping at the first non-PRESENT
     * record - it does NOT require the underlying calendar dates to be
     * contiguous (no gap-detection for weekends/holidays), which would
     * again mean inventing a working-day calendar this schema does not
     * have. This keeps the calculation simple and fully deterministic.
     *
     * No GOOD/WARNING/CRITICAL label is returned - this codebase has no
     * existing attendance-policy/threshold configuration to derive one
     * from, and the module spec explicitly says to just return the
     * percentage and counts rather than invent thresholds when none are
     * configured.
     *
     * @return array{internship_assignment_id: int, total_days: int, present_days: int, absent_days: int, leave_days: int, attendance_percentage: int|float, last_attendance_date: ?string, current_streak: int}
     */
    public function getAssignmentAttendanceSummary(InternshipAssignment $assignment): array
    {
        $counts = DB::table('attendance')
            ->where('internship_assignment_id', $assignment->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $present = (int) ($counts[AttendanceStatus::PRESENT->value] ?? 0);
        $absent = (int) ($counts[AttendanceStatus::ABSENT->value] ?? 0);
        $leave = (int) ($counts[AttendanceStatus::LEAVE->value] ?? 0);
        $totalMarked = $present + $absent + $leave;

        // Zero marked days is a genuinely different case from "0% marked
        // and present" - there is no percentage to speak of yet, so this
        // returns the plain integer 0 rather than a float. round() always
        // returns a float, so every other branch (including a genuine
        // 0% or 100% of at least one marked day) stays a float; only the
        // "nothing marked at all" case is an int. Both serialize as
        // valid JSON numbers, so this never changes what any consumer of
        // the value can do with it.
        $percentage = $totalMarked > 0 ? round(($present / $totalMarked) * 100, 2) : 0;

        $lastAttendanceDate = DB::table('attendance')
            ->where('internship_assignment_id', $assignment->id)
            ->max('attendance_date');

        $currentStreak = 0;
        $recent = Attendance::query()
            ->where('internship_assignment_id', $assignment->id)
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->get(['status']);

        foreach ($recent as $row) {
            if ($row->status !== AttendanceStatus::PRESENT) {
                break;
            }

            $currentStreak++;
        }

        return [
            'internship_assignment_id' => $assignment->id,
            'total_days' => $totalMarked,
            'present_days' => $present,
            'absent_days' => $absent,
            'leave_days' => $leave,
            'attendance_percentage' => $percentage,
            'last_attendance_date' => $lastAttendanceDate,
            'current_streak' => $currentStreak,
        ];
    }

    /**
     * New attendance may only be marked - and an ordinary (non-admin)
     * correction may only happen - while the assignment is ACTIVE.
     * Deliberately stricter than the module spec's literal minimum
     * (which only names COMPLETED/CANCELLED as blocked): an assignment
     * that is still ASSIGNED has no `started_at` yet, so there is no
     * internship period for a date to belong to at all - requiring
     * ACTIVE folds "hasn't started yet" and "already finished/cancelled"
     * into one single, deterministic rule instead of three separate
     * ones.
     */
    private function assertAssignmentIsActive(InternshipAssignment $assignment): void
    {
        if ($assignment->status !== InternshipAssignmentStatus::ACTIVE) {
            throw new AttendanceException(sprintf(
                'Attendance can only be marked or updated for an active internship assignment (this assignment is %s).',
                $assignment->status->value,
            ));
        }
    }

    /**
     * `attendance_date` must fall within [assignment.started_at,
     * assignment.ended_at] (ended_at is optional - an ongoing
     * assignment has no upper bound yet). This is "the internship
     * assignment's internship period" referred to by the module spec:
     * the Internship model itself has no start/end date columns of its
     * own (only a duration_months count), so the assignment's own
     * started_at/ended_at - the only real date-range already in this
     * schema - is what the rule is anchored to.
     */
    private function assertDateWithinPeriod(InternshipAssignment $assignment, Carbon $date): void
    {
        if ($assignment->started_at === null) {
            throw new AttendanceException('This internship assignment has not started yet; attendance cannot be marked.');
        }

        $start = $assignment->started_at->copy()->startOfDay();

        if ($date->lt($start)) {
            throw new AttendanceException(sprintf(
                'Attendance date cannot be before the internship start date (%s).',
                $start->toDateString(),
            ));
        }

        if ($assignment->ended_at !== null) {
            $end = $assignment->ended_at->copy()->startOfDay();

            if ($date->gt($end)) {
                throw new AttendanceException(sprintf(
                    'Attendance date cannot be after the internship end date (%s).',
                    $end->toDateString(),
                ));
            }
        }
    }

    private function assertNotFutureDate(Carbon $date): void
    {
        if ($date->gt(Carbon::today())) {
            throw new AttendanceException('Attendance date cannot be in the future.');
        }
    }

    /**
     * Also normalizes both times to H:i:s before returning them - the
     * request may supply "09:00" (no seconds); normalizing explicitly
     * here (rather than relying on MySQL's TIME column to coerce it on
     * insert) keeps the stored/returned value identical across MySQL
     * (production) and SQLite (the test suite's driver, which has no
     * real TIME type and would otherwise store the raw "09:00" string
     * as-is, unlike MySQL).
     *
     * @return array{0: ?string, 1: ?string} the (possibly forced-null) [check_in_time, check_out_time] pair
     */
    private function assertTimeConsistency(AttendanceStatus $status, ?string $checkIn, ?string $checkOut): array
    {
        if (! $status->allowsTimes()) {
            if ($checkIn !== null || $checkOut !== null) {
                throw new AttendanceException(sprintf('Check-in/check-out time cannot be recorded for %s attendance.', $status->value));
            }

            return [null, null];
        }

        if ($checkIn !== null && $checkOut !== null && ! Carbon::parse($checkOut)->gt(Carbon::parse($checkIn))) {
            throw new AttendanceException('check_out_time must be later than check_in_time.');
        }

        return [$this->normalizeTimeForComparison($checkIn), $this->normalizeTimeForComparison($checkOut)];
    }

    private function normalizeStatus(AttendanceStatus|string $status): AttendanceStatus
    {
        return $status instanceof AttendanceStatus ? $status : AttendanceStatus::from($status);
    }

    /**
     * check_in_time/check_out_time round-trip through MySQL's TIME type
     * with seconds ("09:30:00") even when the request only supplied
     * "09:30" - normalize both sides to H:i:s before comparing so an
     * unrelated update (e.g. remarks only) is never mistaken for a time
     * change purely because of formatting.
     */
    private function normalizeTimeForComparison(?string $value): ?string
    {
        return $value !== null ? Carbon::parse($value)->format('H:i:s') : null;
    }

    private function recordHistory(
        Attendance $attendance,
        ?AttendanceStatus $oldStatus,
        AttendanceStatus $newStatus,
        ?string $oldCheckIn,
        ?string $newCheckIn,
        ?string $oldCheckOut,
        ?string $newCheckOut,
        ?string $oldRemarks,
        ?string $newRemarks,
        User $actingUser,
    ): void {
        AttendanceHistory::create([
            'attendance_id' => $attendance->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_check_in_time' => $oldCheckIn,
            'new_check_in_time' => $newCheckIn,
            'old_check_out_time' => $oldCheckOut,
            'new_check_out_time' => $newCheckOut,
            'old_remarks' => $oldRemarks,
            'new_remarks' => $newRemarks,
            'changed_by' => $actingUser->id,
        ]);
    }
}
