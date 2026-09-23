<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (internship assignment, calendar date) attendance mark -
 * see App\Models\Attendance and App\Services\AttendanceService (the
 * only writer of this table). Attendance is scoped EXCLUSIVELY through
 * InternshipAssignment - Student -> Selected Internship Application ->
 * InternshipAssignment -> Faculty Coordinator -> Attendance - so this
 * table deliberately has no student_id/faculty_id columns of its own;
 * both are always reached by joining through internship_assignment_id
 * (see the model's docblock for why no separate student/faculty
 * relationship is introduced here, mirroring the Internship Assignment
 * module's own "no separate student/internship relationship" rule).
 *
 * `status` is backed by App\Enums\AttendanceStatus (PRESENT/ABSENT/
 * LEAVE) - stored as a short string, matching this schema's existing
 * ALL-CAPS backed-enum convention (InternshipAssignmentStatus et al.),
 * never an arbitrary free-text status.
 *
 * `check_in_time`/`check_out_time` are plain nullable TIME columns -
 * PRESENT is the only status that may carry them (enforced in
 * StoreAttendanceRequest/UpdateAttendanceRequest and re-checked in
 * AttendanceService); ABSENT/LEAVE never do, so daily-only attendance
 * (no clock-in/out tracked at all) is fully supported without forcing
 * faculty to enter times.
 *
 * `marked_by` is nullable + nullOnDelete() (not restrictOnDelete())
 * deliberately: unlike internship_assignments.assigned_by (which
 * blocks deleting the creating admin), losing the identity of who
 * marked a given day's attendance if that user account is later
 * removed should never block the removal or corrupt the attendance
 * record itself - the record (date/status/times) remains fully valid
 * with marked_by simply becoming null.
 *
 * UNIQUENESS: a student can have only one attendance record per
 * internship assignment per calendar date - enforced here via a
 * PERMANENT unique index on (internship_assignment_id,
 * attendance_date), the DB-level half of the two-layer duplicate
 * check described in App\Services\AttendanceService (the other half
 * being the service's own pre-insert exists() check) - mirrors the
 * exact two-layer pattern already used for
 * internship_assignments.application_id.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/unique/regular
 * index on this table is given an explicit short name below rather
 * than relying on Laravel's auto-generated ones - see this module's
 * final report and the Internship Assignment module's own
 * `ia_status_history_assignment_fk` fix for why long generated names
 * are avoided entirely here from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_assignment_id');
            $table->date('attendance_date');
            $table->string('status', 20);
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('marked_by')->nullable();
            $table->timestamps();

            $table->foreign('internship_assignment_id', 'attendance_assignment_fk')
                ->references('id')->on('internship_assignments')
                ->cascadeOnDelete();

            $table->foreign('marked_by', 'attendance_marked_by_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unique(['internship_assignment_id', 'attendance_date'], 'attendance_assignment_date_unique');
            $table->index('attendance_date', 'attendance_date_idx');
            $table->index('status', 'attendance_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
