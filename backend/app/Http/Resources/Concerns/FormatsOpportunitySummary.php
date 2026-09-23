<?php

namespace App\Http\Resources\Concerns;

use App\Models\Internship;

/**
 * Shared by the application-related Resources: normalizes whichever
 * concrete model (JobPosting|Internship) an Application's polymorphic
 * `applicable` relation resolves to into one small, consistent shape -
 * mirrors OpportunityListResource's own job/internship branching, just
 * scoped to what an application listing/detail screen needs
 * (id/type/title/status/company), not the full opportunity payload.
 */
trait FormatsOpportunitySummary
{
    /**
     * @param \App\Models\JobPosting|\App\Models\Internship|null $opportunity
     * @return array<string, mixed>|null
     */
    private function opportunitySummary($opportunity): ?array
    {
        if ($opportunity === null) {
            return null;
        }

        $isInternship = $opportunity instanceof Internship;

        return [
            'id' => $opportunity->id,
            'type' => $isInternship ? 'internship' : 'job',
            'title' => $opportunity->title,
            'status' => $opportunity->status?->value,
            'company' => $opportunity->relationLoaded('company') ? [
                'id' => $opportunity->company?->id,
                'name' => $opportunity->company?->name,
                'logo_path' => $opportunity->company?->logo_path,
            ] : null,
        ];
    }
}
