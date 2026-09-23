<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placement Drive Management - a placement drive is a college
 * recruitment EVENT/container: Company -> Placement Drive -> Job/
 * Internship opportunity -> Applications -> Interviews -> Selections.
 * This table owns none of that downstream data; it only groups
 * existing JobPosting/Internship rows together (see
 * 2024_01_06_000002_create_placement_drive_opportunities_table) and
 * tracks its own DRAFT/PUBLISHED/CLOSED/CANCELLED lifecycle (see
 * App\Enums\PlacementDriveStatus, App\Services\PlacementDriveService).
 *
 * `drive_type`/`status` use PHP-enum-backed string columns, matching
 * this app's Application/Interview modules (App\Enums\ApplicationStatus,
 * App\Enums\InterviewStatus) rather than a native DB ENUM column - the
 * same convention, not the older lowercase App\Enums\OpportunityStatus
 * one; see App\Enums\PlacementDriveStatus's docblock for that
 * distinction.
 *
 * Foreign keys:
 *  - company_id: cascadeOnDelete() - matches job_postings.company_id's
 *    own choice exactly (see 2024_01_01_000013_create_job_postings_table).
 *  - created_by: NOT NULL, restrictOnDelete() - every drive is created
 *    by some authenticated admin; mirrors interviews.created_by's
 *    reasoning (no user-delete endpoint exists in this app today, so
 *    this is a safety default, not a behavior change).
 *  - updated_by / cancelled_by: nullable, nullOnDelete() - safe to lose
 *    just the "last editor"/"who cancelled this" attribution if that
 *    user's account is later removed.
 *
 * scheduled_at/application_deadline are both full timestamps (not
 * date-only, unlike job_postings/internships.application_deadline) -
 * a drive event has a specific scheduled time, and the two columns are
 * directly compared against each other (see
 * App\Services\PlacementDriveService::assertDeadlineNotAfterSchedule())
 * so they need matching granularity. Both are normalized to UTC before
 * being persisted, the same convention InterviewService already
 * established for `interviews.scheduled_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_drives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('drive_type', 20);
            $table->string('venue')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('application_deadline')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_id');
            $table->index('status');
            $table->index('drive_type');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_drives');
    }
};
