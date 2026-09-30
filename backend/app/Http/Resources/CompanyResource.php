<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape for a company (GET/POST/PATCH /api/admin/companies).
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
