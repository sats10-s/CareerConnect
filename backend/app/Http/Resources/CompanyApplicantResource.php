<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application as a recruiter sees it (/api/company/applications). A
 * deliberately separate, allow-listed shape - NOT AdminApplicationResource:
 * a recruiter never receives the student's phone number, any email address
 * (college or personal), home address or resume file. Contact goes through
 * the placement office. The profile block (skills, projects,
 * certifications) is only present on the detail endpoint.
 *
 * @mixin \App\Models\Application
 */
class CompanyApplicantResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'student' => $this->whenLoaded('student', fn () => $this->studentProfile()),
            'opportunity' => $this->opportunitySummary($this->applicable),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'selected_at' => $this->selected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'interviews_count' => $this->whenCounted('interviews'),
            'status_history' => $this->whenLoaded(
                'statusHistory',
                fn () => ApplicationStatusHistoryResource::collection($this->statusHistory)
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function studentProfile(): array
    {
        $student = $this->student;
        $profile = [
            'id' => $student->id,
            'name' => $student->user?->name,
            'roll_number' => $student->roll_number,
            'department' => $student->department?->name,
            'batch' => $student->batch?->name,
            'year' => $student->year,
            'cgpa' => $student->cgpa === null ? null : (float) $student->cgpa,
            'backlogs' => $student->backlogs,
            'linkedin_url' => $student->linkedin_url,
            'github_url' => $student->github_url,
            'portfolio_url' => $student->portfolio_url,
        ];

        if ($student->relationLoaded('skills')) {
            $profile['skills'] = $student->skills->pluck('skill_name')->values();
        }
        if ($student->relationLoaded('projects')) {
            $profile['projects'] = $student->projects->map(fn ($p) => [
                'title' => $p->title,
                'description' => $p->description,
                'technologies' => $p->technologies,
                'project_url' => $p->project_url,
            ])->values();
        }
        if ($student->relationLoaded('certifications')) {
            $profile['certifications'] = $student->certifications->map(fn ($c) => [
                'name' => $c->name,
                'issuing_organization' => $c->issuing_organization,
                'issue_date' => $c->issue_date?->toDateString(),
            ])->values();
        }

        return $profile;
    }
}
