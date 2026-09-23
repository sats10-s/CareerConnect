<?php

namespace App\Http\Resources;

use App\Models\Internship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The lightweight, unified shape used by the student opportunity
 * listing (Api\Student\OpportunityController::index) - deliberately
 * thinner than JobPostingResource/InternshipResource (no eligibility
 * block, no creator/updater) since this is a browse/search list, not
 * a detail view. Wraps either a JobPosting or an Internship model and
 * normalizes the type-specific fields under a common envelope so the
 * frontend can render one list of mixed cards.
 *
 * @mixin \App\Models\JobPosting|\App\Models\Internship
 */
class OpportunityListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isInternship = $this->resource instanceof Internship;

        return [
            'id' => $this->id,
            'type' => $isInternship ? 'internship' : 'job',
            'title' => $this->title,
            'location' => $this->location,
            'work_mode' => $this->work_mode?->value,
            'application_deadline' => $this->application_deadline?->toDateString(),
            'status' => $this->status?->value,

            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company?->id,
                'name' => $this->company?->name,
                'logo_path' => $this->company?->logo_path,
            ]),

            'employment_type' => $isInternship ? null : $this->employment_type?->value,
            'salary_range' => $isInternship ? null : $this->salary_range,
            'duration_months' => $isInternship ? $this->duration_months : null,
            'stipend' => $isInternship ? $this->stipend : null,
        ];
    }
}
