<?php

namespace App\Models\Concerns;

use App\Models\OpportunityEligibilityCriteria;
use App\Models\OpportunityEligibleBatch;
use App\Models\OpportunityEligibleDepartment;
use App\Models\OpportunityEligibleYear;
use App\Models\OpportunityRequiredSkill;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Shared by JobPosting and Internship: the normalized eligibility-
 * criteria data model - scalar cgpa/backlogs thresholds plus
 * multi-value department/batch/year/skill lists, each stored in its
 * own polymorphic table (see the opportunity_eligibility_* /
 * opportunity_eligible_* / opportunity_required_skills migrations)
 * rather than as an opaque JSON blob, so they stay queryable and
 * referentially valid against departments/batches.
 *
 * This increment only persists these criteria - it does NOT compute
 * per-student eligibility against them (that calculation is explicitly
 * out of scope for this increment; see the module spec).
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
     * Persists the full `eligibility` sub-payload (see
     * Store/Update{JobPosting,Internship}Request's `eligibility.*`
     * rules) for this opportunity: upserts the scalar criteria row and
     * replaces each multi-value set wholesale. Only the keys actually
     * present in $eligibility are touched, so a partial update (PATCH)
     * that omits e.g. `skills` leaves the existing required skills
     * alone rather than wiping them.
     *
     * @param array{min_cgpa?:float|null,max_backlogs?:int|null,department_ids?:int[],batch_ids?:int[],years?:int[],skills?:string[]} $eligibility
     */
    public function syncEligibility(array $eligibility): void
    {
        if (array_key_exists('min_cgpa', $eligibility) || array_key_exists('max_backlogs', $eligibility)) {
            $this->eligibilityCriteria()->updateOrCreate([], [
                'min_cgpa' => $eligibility['min_cgpa'] ?? null,
                'max_backlogs' => $eligibility['max_backlogs'] ?? null,
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
                collect($eligibility['skills'])->unique()->map(fn ($name) => ['skill_name' => $name])->all()
            );
        }
    }
}
