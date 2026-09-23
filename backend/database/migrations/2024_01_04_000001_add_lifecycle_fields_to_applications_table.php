<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremental addition to the existing `applications` table
 * (2024_01_01_000015_create_applications_table, already applied - not
 * touched) for the Application Lifecycle increment.
 *
 * Schema decisions:
 *  - `student_id` + `applicable_type` + `applicable_id` (the existing
 *    polymorphic pair) is deliberately NOT renamed to
 *    opportunity_type/opportunity_id - the eligibility tables from the
 *    prior increment use that naming, but `applications` already has
 *    its own established `applicable` morph (see Application::applicable(),
 *    JobPosting::applications(), Internship::applications()) with a
 *    working unique constraint (`applications_unique_per_student`) and
 *    a `morphs()`-generated composite index on
 *    (applicable_type, applicable_id) - renaming it would mean editing
 *    an already-applied migration, which is exactly what "do not
 *    duplicate job/internship-specific foreign keys if the existing
 *    polymorphic design is already established" rules out.
 *  - `status` and `applied_at` already exist on the table (string(20)
 *    default 'applied', timestamp default CURRENT_TIMESTAMP) - only
 *    the new lifecycle columns are added here. The `status` column's
 *    default of the OLD placeholder value 'applied' is harmless: every
 *    write path in this increment (App\Services\ApplicationService)
 *    always sets `status` explicitly to an App\Enums\ApplicationStatus
 *    value, so the stale column default is simply never relied upon.
 *  - `applied_at` already had no index; this migration adds one, since
 *    both the student and admin application listings default-sort by
 *    it (see Api\Student\ApplicationController::index /
 *    Api\Admin\ApplicationController::index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('applied_at');
            $table->timestamp('selected_at')->nullable()->after('withdrawn_at');
            $table->text('rejection_reason')->nullable()->after('selected_at');
            $table->text('notes')->nullable()->after('rejection_reason');

            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['applied_at']);
            $table->dropColumn(['withdrawn_at', 'selected_at', 'rejection_reason', 'notes']);
        });
    }
};
