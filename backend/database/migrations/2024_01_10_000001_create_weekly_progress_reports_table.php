<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (internship assignment, week number) weekly progress
 * report - see App\Models\WeeklyProgressReport and
 * App\Services\WeeklyProgressReportService (the only writer of this
 * table). Weekly Progress Reports are scoped EXCLUSIVELY through
 * InternshipAssignment - Student -> Selected Internship Application ->
 * InternshipAssignment -> Faculty Coordinator -> Weekly Progress
 * Reports - so this table deliberately has no student_id/faculty_id/
 * internship_id/company_id columns of its own; all four are always
 * reached by joining through internship_assignment_id, exactly
 * mirroring the Attendance Management module's own "no separate
 * student/internship relationship" rule (see attendance's migration
 * docblock).
 *
 * `status` is backed by App\Enums\WeeklyReportStatus (DRAFT/SUBMITTED/
 * UNDER_REVIEW/CHANGES_REQUESTED/APPROVED) - stored as a short string,
 * matching this schema's existing ALL-CAPS backed-enum convention.
 *
 * `week_number` is unsignedInteger, not a foreign key - it is simply
 * this report's ordinal position within the internship (1, 2, 3, ...),
 * validated to be >= 1 and unique per assignment below.
 *
 * `week_start_date`/`week_end_date` are plain DATE columns - a
 * calendar week, not a timestamp. See
 * App\Models\WeeklyProgressReport's setWeekStartDateAttribute()/
 * setWeekEndDateAttribute() mutators for why the raw attribute is
 * explicitly normalized to Y-m-d before it ever reaches Eloquent's
 * date-cast storage path: without that mutator, the same bug the
 * Attendance Management module hit (Eloquent's 'date' cast writes the
 * connection's full datetime format on save, e.g.
 * "2026-09-23 00:00:00", which silently breaks date-range filtering
 * and assertDatabaseHas()-style exact-date checks) would resurface
 * here too.
 *
 * `work_done` is NOT nullable - a report always has SOME work_done
 * text once it exists (enforced as `required` at creation by
 * StoreWeeklyProgressReportRequest); it may only be blanked out again
 * during an in-progress DRAFT/CHANGES_REQUESTED edit
 * (UpdateWeeklyProgressReportRequest allows it nullable there), which
 * is exactly why submit() independently re-validates that work_done is
 * non-empty before allowing SUBMITTED - defense in depth, not
 * redundancy.
 *
 * `student_submitted_at`/`faculty_reviewed_at` are nullable timestamps
 * set by App\Services\WeeklyProgressReportService::submit()/
 * startReview()|approve()|requestChanges() respectively -
 * `faculty_review_remarks` is nullable free text, only ever written by
 * a faculty review action, never by the student.
 *
 * UNIQUENESS: a student can have only ONE weekly report per internship
 * assignment per week number - enforced here via a PERMANENT unique
 * index on (internship_assignment_id, week_number), the DB-level half
 * of the two-layer duplicate check in
 * App\Services\WeeklyProgressReportService (the other half being the
 * service's own pre-insert exists() check) - mirrors the exact
 * two-layer pattern already used for
 * attendance.(internship_assignment_id, attendance_date). Deliberately
 * NOT keyed by student_id - student ownership already comes through
 * the assignment, per the module spec's explicit instruction.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/unique/regular
 * index on this table is given an explicit short name below rather
 * than relying on Laravel's auto-generated ones - see the Internship
 * Assignment module's `ia_status_history_assignment_fk` fix and the
 * Attendance module's own explicit names for why long generated names
 * are avoided entirely here from the start, rather than repaired after
 * the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_assignment_id');
            $table->unsignedInteger('week_number');
            $table->date('week_start_date');
            $table->date('week_end_date');
            $table->text('work_done');
            $table->text('learning_outcomes')->nullable();
            $table->text('challenges')->nullable();
            $table->text('next_week_plan')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('student_submitted_at')->nullable();
            $table->timestamp('faculty_reviewed_at')->nullable();
            $table->text('faculty_review_remarks')->nullable();
            $table->timestamps();

            $table->foreign('internship_assignment_id', 'weekly_report_assignment_fk')
                ->references('id')->on('internship_assignments')
                ->cascadeOnDelete();

            $table->unique(['internship_assignment_id', 'week_number'], 'weekly_report_assignment_week_unique');
            $table->index('status', 'weekly_report_status_idx');
            $table->index('week_start_date', 'weekly_report_week_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_progress_reports');
    }
};
