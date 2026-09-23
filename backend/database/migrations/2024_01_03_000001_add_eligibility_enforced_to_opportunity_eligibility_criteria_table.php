<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the admin-controlled enforcement switch for opportunity
 * eligibility (see App\Services\OpportunityEligibilityService and
 * App\Models\Concerns\HasEligibilityCriteria::syncEligibility()).
 * Lives on opportunity_eligibility_criteria rather than job_postings/
 * internships since it's conceptually part of "the criteria row" for
 * a given opportunity, and that table already exists with exactly one
 * row per opportunity - no new table needed, per the product rule
 * that eligibility criteria are reused, not duplicated.
 *
 * Default false: per this increment's product rule, an opportunity
 * with eligibility_enforced left at its default is visible to, and
 * appliable-to by, every student - eligibility is purely informational
 * until a PLACEMENT_ADMIN/SUPER_ADMIN explicitly turns enforcement on
 * for that specific opportunity. This column does NOT affect listing
 * visibility (GET /api/student/opportunities is untouched by this
 * increment) - it only affects whether the student eligibility
 * evaluation is treated as enforceable.
 *
 * Purely additive (a single ADD COLUMN, no index/FK), so it carries
 * none of the identifier-length risk the earlier opportunity_*
 * migrations hit on MySQL. Does not touch 2024_01_02_000001 through
 * 2024_01_02_000007 (already applied).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunity_eligibility_criteria', function (Blueprint $table) {
            $table->boolean('eligibility_enforced')->default(false)->after('opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::table('opportunity_eligibility_criteria', function (Blueprint $table) {
            $table->dropColumn('eligibility_enforced');
        });
    }
};
