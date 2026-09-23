<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every weekly progress report STATUS
 * change (create draft/submit/start review/approve/request changes) -
 * see App\Models\WeeklyProgressReportStatusHistory and
 * App\Services\WeeklyProgressReportService (the only writer of this
 * table). No `updated_at` (UPDATED_AT = null on the model) - a history
 * row is only ever inserted, never modified, mirroring
 * internship_assignment_status_history/attendance_history exactly.
 *
 * `from_status` is nullable for the very first row a report ever gets
 * (null -> DRAFT, written the moment the report is created).
 *
 * `changed_by` is NOT nullable (restrictOnDelete()) - unlike
 * attendance.marked_by (which is nullOnDelete(), since losing who
 * marked a day's attendance should never block deleting that user),
 * every weekly-report lifecycle transition has an identifiable actor
 * (the student who created/submitted it, or the faculty member who
 * reviewed it) and that identity must never silently disappear from
 * the audit trail - mirrors attendance_history.changed_by's own
 * NOT NULL + restrictOnDelete() choice exactly, and matches this
 * table's field list in the module spec (only `remarks` and
 * `from_status` are marked nullable there; `changed_by` is not).
 *
 * The `weekly_progress_report_id` and `changed_by` foreign keys are
 * given explicit short constraint names
 * (`weekly_report_history_report_fk` / `weekly_report_history_user_fk`)
 * rather than relying on Laravel's long auto-generated ones - see this
 * table's sibling migration's own docblock for why every constraint in
 * this module is named explicitly from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_progress_report_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_progress_report_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('weekly_progress_report_id', 'weekly_report_history_report_fk')
                ->references('id')->on('weekly_progress_reports')
                ->cascadeOnDelete();

            $table->foreign('changed_by', 'weekly_report_history_user_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index(['weekly_progress_report_id', 'created_at'], 'weekly_report_history_report_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_progress_report_status_history');
    }
};
