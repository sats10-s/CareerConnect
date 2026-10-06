<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for one job posting - used by the admin
 * create/show/update endpoints and by the student single-job detail
 * endpoint (Api\Student\OpportunityController::showJob, which also
 * merges in a `my_eligibility` sibling key with that specific
 * student's own evaluation - see OpportunityEligibilityService). The
 * `eligibility` block here is always the admin-configured CRITERIA
 * (same shape for both audiences - students are allowed to read it,
 * never to modify it), not any one student's status against it. The
 * relations it reads (eligibilityCriteria/eligibleDepartments.department/
 * eligibleBatches.batch/eligibleYears/requiredSkills) should be
 * eager-loaded by the caller to avoid N+1s - see the controllers'
 * ->load() calls.
 *
 * @mixin \App\Models\JobPosting
 */
class JobPostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'job',
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'salary_range' => $this->salary_range,
            'employment_type' => $this->employment_type?->value,
            'work_mode' => $this->work_mode?->value,
            'application_deadline' => $this->application_deadline?->toDateString(),
            'openings' => $this->openings,
            'status' => $this->status?->value,
            'is_active' => (bool) $this->is_active,
            // ADMIN (placement office) or COMPANY (created from the company portal).
            'source' => $this->source,
            'submitted_for_review_at' => $this->submitted_for_review_at?->toIso8601String(),

            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'industry' => $this->company->industry,
                'logo_path' => $this->company->logo_path,
            ]),

            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),

            'updater' => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),

            'eligibility' => [
                'enforced' => (bool) ($this->eligibilityCriteria?->eligibility_enforced ?? false),
                'minimum_cgpa' => $this->whenLoaded('eligibilityCriteria', fn () => $this->eligibilityCriteria?->min_cgpa === null
                    ? null
                    : (float) $this->eligibilityCriteria->min_cgpa),
                'maximum_backlogs' => $this->whenLoaded('eligibilityCriteria', fn () => $this->eligibilityCriteria?->max_backlogs),
                'departments' => $this->whenLoaded('eligibleDepartments', fn () => $this->eligibleDepartments->map(fn ($row) => [
                    'id' => $row->department_id,
                    'name' => $row->department?->name,
                ])->values()),
                'batches' => $this->whenLoaded('eligibleBatches', fn () => $this->eligibleBatches->map(fn ($row) => [
                    'id' => $row->batch_id,
                    'name' => $row->batch?->name,
                ])->values()),
                'years' => $this->whenLoaded('eligibleYears', fn () => $this->eligibleYears->pluck('year')->values()),
                'required_skills' => $this->whenLoaded('requiredSkills', fn () => $this->requiredSkills->pluck('skill_name')->values()),
            ],

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
