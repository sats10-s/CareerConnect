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
 * (SQLSTATE 42000 / error 1059). Same fix applied to every other
 * opportunity_* migration below this one for the same reason.
 *
 * RECONCILIATION NOTE (do not remove): the very first attempt at this
 * migration - before the index above had an explicit name - hit that
 * 1059 error on production MySQL. What actually happened is more
 * subtle than "the CREATE TABLE failed": Laravel's MySQL schema
 * builder issues the base `CREATE TABLE` (columns only) and each
 * index/key (including morphs()'s and the unique() below) as
 * SEPARATE statements, and MySQL auto-commits each DDL statement
 * independently - it does not roll back earlier statements in the
 * same migration when a later one fails. So the bare `CREATE TABLE`
 * succeeded and was permanently committed; the very next statement
 * (ADD INDEX with the too-long auto-generated name) then failed,
 * aborting the migration before the unique() constraint was ever
 * added - and since the migration as a whole threw, Laravel never
 * recorded it as Ran, so `php artisan migrate` tried the full
 * `Schema::create()` again and hit "table already exists" (1050).
 *
 * The `Schema::hasTable()` branch below handles that specific,
 * already-happened state non-destructively: it does NOT drop or
 * recreate the table (no data is touched), it only adds whatever the
 * first attempt never got to - the morphs index and the unique
 * constraint - guarded by hasIndex() so it's also safe to run again.
 * A fresh install (table doesn't exist yet) is unaffected and takes
 * the normal Schema::create() path below.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opportunity_eligibility_criteria')) {
            Schema::table('opportunity_eligibility_criteria', function (Blueprint $table) {
                if (! Schema::hasIndex('opportunity_eligibility_criteria', 'opportunity_eligibility_opportunity_idx')) {
                    $table->index(['opportunity_type', 'opportunity_id'], 'opportunity_eligibility_opportunity_idx');
                }

                if (! Schema::hasIndex('opportunity_eligibility_criteria', 'opportunity_eligibility_criteria_unique')) {
                    $table->unique(['opportunity_type', 'opportunity_id'], 'opportunity_eligibility_criteria_unique');
                }
            });

            return;
        }

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
