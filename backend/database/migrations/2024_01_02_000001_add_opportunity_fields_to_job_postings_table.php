<?php

use App\Enums\OpportunityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremental addition to the existing (placeholder) `job_postings`
 * table for the Jobs & Internships Backend Foundation increment. This
 * does NOT touch 2024_01_01_000013_create_job_postings_table, which is
 * already applied in dev and must never be edited in place.
 *
 * Schema decisions (see the final report for full rationale):
 *  - `posted_by` (existing) is reused as the "creator" - JobPosting
 *    gains a new creator() relation pointing at it rather than the
 *    column being renamed. `updated_by` is new, set only by the
 *    update endpoint.
 *  - `salary_range` and `is_active` (existing) are left untouched;
 *    `status` is an additive lifecycle column (see OpportunityStatus),
 *    not a replacement for `is_active`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('status', 20)->default(OpportunityStatus::DRAFT->value)->after('is_active');
            $table->string('employment_type', 20)->nullable()->after('status');
            $table->string('work_mode', 20)->nullable()->after('employment_type');
            $table->date('application_deadline')->nullable()->after('work_mode');
            $table->unsignedSmallInteger('openings')->nullable()->after('application_deadline');
            $table->foreignId('updated_by')->nullable()->after('posted_by')->constrained('users')->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropIndex(['status']);
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['status', 'employment_type', 'work_mode', 'application_deadline', 'openings']);
        });
    }
};
