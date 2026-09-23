<?php

use App\Enums\OpportunityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremental addition to the existing (placeholder) `internships`
 * table - mirrors 2024_01_02_000001_add_opportunity_fields_to_job_postings_table.
 * Does NOT touch 2024_01_01_000014_create_internships_table.
 *
 * Schema decisions:
 *  - `duration_months` and `stipend` (existing) are reused as-is for
 *    the internship "duration"/"stipend" fields - no new columns are
 *    added for these, so no `duration` column exists here (see the
 *    final report).
 *  - No `employment_type` here - that concept is job-posting specific
 *    (full-time/part-time/contract/temporary); an internship's
 *    "duration" is already captured by `duration_months`.
 *  - `posted_by` is reused as "creator" (see JobPosting's migration
 *    for the same reasoning); `updated_by` is new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internships', function (Blueprint $table) {
            $table->string('status', 20)->default(OpportunityStatus::DRAFT->value)->after('is_active');
            $table->string('work_mode', 20)->nullable()->after('status');
            $table->date('application_deadline')->nullable()->after('work_mode');
            $table->unsignedSmallInteger('openings')->nullable()->after('application_deadline');
            $table->foreignId('updated_by')->nullable()->after('posted_by')->constrained('users')->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('internships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropIndex(['status']);
        });

        Schema::table('internships', function (Blueprint $table) {
            $table->dropColumn(['status', 'work_mode', 'application_deadline', 'openings']);
        });
    }
};
