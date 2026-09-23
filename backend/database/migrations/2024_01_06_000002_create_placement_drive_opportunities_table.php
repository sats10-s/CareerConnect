<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The join between a placement drive and the existing JobPosting/
 * Internship rows attached to it - deliberately just a grouping
 * record (id, placement_drive_id, opportunity_type, opportunity_id,
 * timestamps). It never duplicates job/internship information, and
 * App\Services\PlacementDriveService never writes to `job_postings`/
 * `internships` through it - attaching an opportunity to a drive does
 * not change that opportunity's own status.
 *
 * Polymorphic via `opportunity_type`/`opportunity_id`, storing the
 * full model class name exactly like `applications.applicable_type`
 * and `opportunity_eligibility_criteria.opportunity_type` already do -
 * no morph map is registered anywhere in this app.
 *
 * `morphs()` is given an explicit short index name for the same
 * reason 2024_01_02_000003_create_opportunity_eligibility_criteria_table
 * already had to: this table's own name
 * (`placement_drive_opportunities`, 30 chars) pushes Laravel's default
 * auto-generated index name
 * (`placement_drive_opportunities_opportunity_type_opportunity_id_index`,
 * 69 chars) past MySQL's 64-character identifier limit (SQLSTATE 42000
 * / error 1059) - explicit short names avoid that failure mode
 * entirely rather than needing the same after-the-fact reconciliation
 * that migration's docblock describes.
 *
 * The unique constraint (placement_drive_id, opportunity_type,
 * opportunity_id) is what "prevent the same opportunity from being
 * attached to the same drive" actually means at the DB level -
 * App\Services\PlacementDriveService::attachOpportunity() checks this
 * up front for a clean business error, and also catches the
 * constraint violation itself as a race-condition safety net (the
 * same pattern App\Services\ApplicationService::apply() already uses
 * for duplicate applications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_drive_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_drive_id')->constrained('placement_drives')->cascadeOnDelete();
            $table->morphs('opportunity', 'pdo_opportunity_idx'); // opportunity_type, opportunity_id
            $table->timestamps();

            $table->unique(['placement_drive_id', 'opportunity_type', 'opportunity_id'], 'pdo_unique_per_drive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_drive_opportunities');
    }
};
