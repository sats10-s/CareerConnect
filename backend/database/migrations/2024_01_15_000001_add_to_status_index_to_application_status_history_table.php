<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports & Analytics module - the ONLY schema change this module
 * makes. Every reporting query reuses existing tables/columns; this
 * single index is added because it is genuinely needed, not
 * speculatively: App\Services\Reporting\PlacementReportService::
 * countStudentsEverReaching() (backing the "students_shortlisted"
 * placement metric - see that class's docblock for why this figure is
 * computed from ApplicationStatusHistory rather than the current
 * Application.status column) filters application_status_history by
 * `to_status` on every placement summary request. Without an index on
 * that column, this is a full table scan of an append-only,
 * ever-growing audit table on every dashboard/placement-report load -
 * exactly the kind of query this module's "avoid N+1/performance
 * regressions, add only justified indexes" instruction is about.
 *
 * No other new index is added anywhere in this module: every other
 * reporting query filters/joins on columns that already carry an
 * index from an earlier migration (applications.status/applied_at,
 * attendance.attendance_date/status, internship_assignments.*, etc. -
 * see each one's own migration).
 *
 * Short explicit index name, matching this schema's established
 * MySQL 9.5 identifier-length convention (see e.g. the Attendance
 * Management module's `attendance_assignment_fk` naming).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_status_history', function (Blueprint $table) {
            $table->index('to_status', 'app_status_history_to_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('application_status_history', function (Blueprint $table) {
            $table->dropIndex('app_status_history_to_status_idx');
        });
    }
};
