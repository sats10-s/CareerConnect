<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for one job posting - used by the admin
 * create/show/update endpoints and by the student single-job detail
 * endpoint (Api\Student\OpportunityController::showJob). The
 * `eligibility` block is always included; the relations it reads
 * (eligibilityCriteria/eligibleDepartments/eligibleBatches/
 * eligibleYears/requiredSkills) should be eager-loaded by the caller
 * to avoid N+1s - see the controllers' ->load() calls.
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
                'min_cgpa' => $this->whenLoaded('eligibilityCriteria', fn () => $this->eligibilityCriteria?->min_cgpa === null
                    ? null
                    : (float) $this->eligibilityCriteria->min_cgpa),
                'max_backlogs' => $this->whenLoaded('eligibilityCriteria', fn () => $this->eligibilityCriteria?->max_backlogs),
                'department_ids' => $this->whenLoaded('eligibleDepartments', fn () => $this->eligibleDepartments->pluck('department_id')->values()),
                'batch_ids' => $this->whenLoaded('eligibleBatches', fn () => $this->eligibleBatches->pluck('batch_id')->values()),
                'years' => $this->whenLoaded('eligibleYears', fn () => $this->eligibleYears->pluck('year')->values()),
                'skills' => $this->whenLoaded('requiredSkills', fn () => $this->requiredSkills->pluck('skill_name')->values()),
            ],

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
