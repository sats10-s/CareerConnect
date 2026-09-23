<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scalar eligibility thresholds (minimum CGPA, maximum backlogs) for a
 * JobPosting or Internship, one row per opportunity. Polymorphic via
 * `opportunity_type`/`opportunity_id` - the same pattern the existing
 * `applications` table already uses for `applicable_type`/
 * `applicable_id` (no morph map is registered anywhere in this app, so
 * `opportunity_type` stores the full model class name, exactly like
 * `applicable_type` does).
 *
 * Kept separate from the multi-value eligibility tables below
 * (departments/batches/years/skills) since this one is a single
 * scalar row per opportunity rather than a list.
 *
 * "Minimum CGPA filter" semantics: `min_cgpa` is the lowest CGPA a
 * student may have to be eligible (student.cgpa >= min_cgpa) and
 * `max_backlogs` is the highest backlog count still eligible
 * (student.backlogs <= max_backlogs) - see the final report's
 * "schema decisions needing confirmation" for this interpretation.
 *
 * `morphs('opportunity')` is given an explicit index name below
 * because Laravel's auto-generated one -
 * `opportunity_eligibility_criteria_opportunity_type_opportunity_id_index`
 * (70 chars) - exceeds MySQL's 64-character identifier limit
 * (SQLSTATE 42000 / error 1059), which fails the whole CREATE TABLE
 * statement on a MySQL production database even though it's silently
 * fine on SQLite (the test suite's driver, which doesn't enforce that
 * limit). Same fix applied to every other opportunity_* migration
 * below this one for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_eligibility_criteria', function (Blueprint $table) {
            $table->id();
            $table->morphs('opportunity', 'opportunity_eligibility_opportunity_idx'); // opportunity_type, opportunity_id
            $table->decimal('min_cgpa', 4, 2)->nullable();
            $table->unsignedTinyInteger('max_backlogs')->nullable();
            $table->timestamps();

            $table->unique(['opportunity_type', 'opportunity_id'], 'opportunity_eligibility_criteria_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_eligibility_criteria');
    }
};
