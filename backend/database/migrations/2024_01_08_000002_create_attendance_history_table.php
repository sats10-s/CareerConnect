<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only correction/creation audit trail for `attendance` - see
 * App\Models\AttendanceHistory and App\Services\AttendanceService (the
 * only writer). Every attendance creation and every attendance update
 * that changes status/check_in_time/check_out_time/remarks writes
 * exactly one row here, capturing the full old-vs-new snapshot of all
 * four fields at once (not just a single from/to status pair, unlike
 * internship_assignment_status_history) - a correction can touch
 * status and times and remarks together in one PATCH, and this shape
 * records that as one coherent event rather than three separate ones.
 * History rows are never updated or deleted by any application code -
 * no controller/service exposes an edit or delete path for this table.
 *
 * `changed_at` (not `created_at`) is this table's literal field name
 * per the module spec - reconciled with this codebase's existing
 * append-only-history pattern (no `updated_at`, a single
 * auto-populated timestamp, useCurrent()) by mapping Eloquent's
 * CREATED_AT constant to this column name on the model rather than
 * introducing a second, redundant timestamp column - see
 * App\Models\AttendanceHistory.
 *
 * `changed_by` is NOT nullable (every row is always written by an
 * authenticated actor - the marking/updating faculty member or an
 * admin correction, never a system/background process) and
 * restrictOnDelete(), mirroring internship_assignment_faculty_history's
 * changed_by exactly (not internship_assignment_status_history's
 * nullable one, which exists only for a hypothetical system-driven
 * change this module has no equivalent of).
 *
 * The top-level `remarks` column (distinct from old_remarks/
 * new_remarks, which track changes to the ATTENDANCE record's own
 * remarks field) exists per the module spec's literal field list as a
 * slot for a note about the correction/change event itself. No Store/
 * Update request in this increment collects such a note, so it is
 * always null for now - left in place, unpopulated, rather than
 * omitted, so a future correction-reason field can be added without a
 * schema change (see the final report's design notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id');
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->time('old_check_in_time')->nullable();
            $table->time('new_check_in_time')->nullable();
            $table->time('old_check_out_time')->nullable();
            $table->time('new_check_out_time')->nullable();
            $table->text('old_remarks')->nullable();
            $table->text('new_remarks')->nullable();
            $table->foreignId('changed_by');
            $table->timestamp('changed_at')->useCurrent();
            $table->text('remarks')->nullable();

            $table->foreign('attendance_id', 'attendance_history_attendance_fk')
                ->references('id')->on('attendance')
                ->cascadeOnDelete();

            $table->foreign('changed_by', 'attendance_history_changed_by_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index(['attendance_id', 'changed_at'], 'attendance_history_attendance_changed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_history');
    }
};
