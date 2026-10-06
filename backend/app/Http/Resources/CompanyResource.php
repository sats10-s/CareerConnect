<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape for a company: the admin endpoints (/api/admin/companies), the
 * Super Admin approval queue and the recruiter's own /api/company/me.
 * Posting counts are present only when the controller loaded them
 * (withCount); `postings` only on the detail endpoint.
 *
 * @mixin \App\Models\Company
 */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'industry' => $this->industry,
            'website' => $this->website,
            'description' => $this->description,
            'logo_path' => $this->logo_path,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'source' => $this->source?->value,
            'headquarters' => $this->headquarters,
            'company_size' => $this->company_size,
            'registration_number' => $this->registration_number,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'is_active' => $this->owner->is_active,
                'last_login_at' => $this->owner->last_login_at?->toIso8601String(),
            ] : null),
            'job_postings_count' => $this->whenCounted('jobPostings'),
            'internships_count' => $this->whenCounted('internships'),
            'postings' => $this->when($this->relationLoaded('jobPostings') && $this->relationLoaded('internships'), fn () => $this->jobPostings
                ->map(fn ($job) => ['id' => $job->id, 'type' => 'job', 'title' => $job->title, 'status' => $job->status?->value, 'created_at' => $job->created_at?->toIso8601String()])
                ->concat($this->internships->map(fn ($internship) => ['id' => $internship->id, 'type' => 'internship', 'title' => $internship->title, 'status' => $internship->status?->value, 'created_at' => $internship->created_at?->toIso8601String()]))
                ->sortByDesc('created_at')
                ->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
