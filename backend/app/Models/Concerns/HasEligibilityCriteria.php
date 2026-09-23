<?php

namespace App\Models\Concerns;

use App\Models\OpportunityEligibilityCriteria;
use App\Models\OpportunityEligibleBatch;
use App\Models\OpportunityEligibleDepartment;
use App\Models\OpportunityEligibleYear;
use App\Models\OpportunityRequiredSkill;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared by JobPosting and Internship: the normalized eligibility-
 * criteria data model - the admin-controlled `eligibility_enforced`
 * switch and cgpa/backlogs thresholds, plus multi-value department/
 * batch/year/skill lists, each stored in its own polymorphic table
 * (see the opportunity_eligibility_* / opportunity_eligible_* /
 * opportunity_required_skills migrations) rather than as an opaque
 * JSON blob, so they stay queryable and referentially valid against
 * departments/batches.
 *
 * Product rule (see App\Services\OpportunityEligibilityService): an
 * opportunity is ALWAYS visible to every student regardless of these
 * criteria. `eligibility_enforced` only controls whether a student's
 * personal evaluation against these criteria is treated as
 * enforceable (and so may later block applying) or purely
 * informational. The admin decides who is eligible by configuring
 * these criteria - this trait never infers eligibility on its own.
 */
trait HasEligibilityCriteria
{
    public function eligibilityCriteria(): MorphOne
    {
        return $this->morphOne(OpportunityEligibilityCriteria::class, 'opportunity');
    }

    public function eligibleDepartments(): MorphMany
    {
        return $this->morphMany(OpportunityEligibleDepartment::class, 'opportunity');
    }

    public function eligibleBatches(): MorphMany
    {
        return $this->morphMany(OpportunityEligibleBatch::class, 'opportunity');
    }

    public function eligibleYears(): MorphMany
    {
        return $this->morphMany(OpportunityEligibleYear::class, 'opportunity');
    }

    public function requiredSkills(): MorphMany
    {
        return $this->morphMany(OpportunityRequiredSkill::class, 'opportunity');
    }

    /**
     * Persists the eligibility configuration for this opportunity:
     * upserts the scalar criteria row (`eligibility_enforced`,
     * `min_cgpa`, `max_backlogs`) and replaces each multi-value set
     * (departments/batches/years/skills) wholesale. Only the keys
     * actually present in $eligibility are touched - a partial update
     * that omits e.g. `skills` leaves the existing required skills
     * alone rather than wiping them, and a scalar field left out of
     * the payload keeps its previously stored value rather than being
     * reset to null. Runs as its own transaction (nested safely inside
     * a caller's own DB::transaction() via a savepoint, if any) so a
     * failure partway through never leaves the criteria half-updated.
     *
     * An unspecified criterion means that dimension is unrestricted -
     * this method never treats "not configured" as "nobody qualifies".
     *
     * @param array{eligibility_enforced?:bool,min_cgpa?:float|null,max_backlogs?:int|null,department_ids?:int[],batch_ids?:int[],years?:int[],skills?:string[]} $eligibility
     */
    public function syncEligibility(array $eligibility): void
    {
        DB::transaction(function () use ($eligibility) {
            if (array_key_exists('eligibility_enforced', $eligibility)
                || array_key_exists('min_cgpa', $eligibility)
                || array_key_exists('max_backlogs', $eligibility)) {
                $current = $this->eligibilityCriteria;

                $this->eligibilityCriteria()->updateOrCreate([], [
                    'eligibility_enforced' => array_key_exists('eligibility_enforced', $eligibility)
                        ? (bool) $eligibility['eligibility_enforced']
                        : (bool) ($current->eligibility_enforced ?? false),
                    'min_cgpa' => array_key_exists('min_cgpa', $eligibility)
                        ? $eligibility['min_cgpa']
                        : ($current->min_cgpa ?? null),
                    'max_backlogs' => array_key_exists('max_backlogs', $eligibility)
                        ? $eligibility['max_backlogs']
                        : ($current->max_backlogs ?? null),
                ]);
            }

            if (array_key_exists('department_ids', $eligibility)) {
                $this->eligibleDepartments()->delete();
                $this->eligibleDepartments()->createMany(
                    collect($eligibility['department_ids'])->unique()->map(fn ($id) => ['department_id' => $id])->all()
                );
            }

            if (array_key_exists('batch_ids', $eligibility)) {
                $this->eligibleBatches()->delete();
                $this->eligibleBatches()->createMany(
                    collect($eligibility['batch_ids'])->unique()->map(fn ($id) => ['batch_id' => $id])->all()
                );
            }

            if (array_key_exists('years', $eligibility)) {
                $this->eligibleYears()->delete();
                $this->eligibleYears()->createMany(
                    collect($eligibility['years'])->unique()->map(fn ($year) => ['year' => $year])->all()
                );
            }

            if (array_key_exists('skills', $eligibility)) {
                $this->requiredSkills()->delete();
                $this->requiredSkills()->createMany(
                    static::normalizeSkillNames($eligibility['skills'])->map(fn ($name) => ['skill_name' => $name])->all()
                );
            }
        });
    }

    /**
     * Trims and collapses internal whitespace in each skill name, then
     * de-duplicates case-insensitively (first-seen casing wins) and
     * drops anything that's empty after trimming - so "Java", " java ",
     * and "JAVA" submitted together are stored once, as "Java". This
     * also matches opportunity_required_skills' unique constraint,
     * which sits on a case-insensitive collation (utf8mb4_unicode_ci),
     * so storing undeduplicated case-variant rows would fail there
     * anyway.
     *
     * @param string[] $skills
     * @return Collection<int, string>
     */
    public static function normalizeSkillNames(array $skills): Collection
    {
        return collect($skills)
            ->map(fn ($skill) => trim(preg_replace('/\s+/', ' ', (string) $skill)))
            ->filter(fn ($skill) => $skill !== '')
            ->unique(fn ($skill) => mb_strtolower($skill))
            ->values();
    }
}
