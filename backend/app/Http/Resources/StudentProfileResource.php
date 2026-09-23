<?php

namespace App\Http\Resources;

use App\Services\Student\ProfileCompletionCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full Student Profile screen's payload - personal, academic,
 * skills/projects/certifications/experience, professional links,
 * resume status, and the deterministic completion percentage, all in
 * one response so the frontend can render the whole page from a
 * single GET and refresh its entire local state from any mutation's
 * response.
 *
 * Deliberately separate from UserResource (used by /auth/me and
 * login/register) - this is a much heavier, profile-screen-specific
 * payload, and keeping it out of UserResource means the
 * authentication/session-check path this module must not touch stays
 * exactly as it was.
 *
 * @mixin \App\Models\User
 */
class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Student $student */
        $student = $this->student;

        return [
            'id' => $this->id,
            'name' => $this->name,
            // The login identity - immutable from this endpoint (see
            // UpdateProfileRequest's `college_email`/`email` => prohibited
            // rule). Named distinctly from `personal_email` below so the
            // frontend can render one as read-only and the other as
            // editable without any ambiguity.
            'college_email' => $this->email,
            'personal_email' => $student->personal_email,
            'phone' => $student->phone,

            'roll_number' => $student->roll_number,
            'department_id' => $student->department_id,
            'department' => $student->department?->name,
            'batch_id' => $student->batch_id,
            'batch' => $student->batch?->name,
            'year' => $student->year,
            'cgpa' => $student->cgpa === null ? null : (float) $student->cgpa,
            'backlogs' => $student->backlogs,

            'skills' => $student->skills->map(fn ($skill) => [
                'id' => $skill->id,
                'skill_name' => $skill->skill_name,
            ])->values(),

            'projects' => $student->projects->map(fn ($project) => [
                'id' => $project->id,
                'title' => $project->title,
                'description' => $project->description,
                'technologies' => $project->technologies,
                'project_url' => $project->project_url,
            ])->values(),

            'certifications' => $student->certifications->map(fn ($certification) => [
                'id' => $certification->id,
                'name' => $certification->name,
                'issuing_organization' => $certification->issuing_organization,
                'issue_date' => $certification->issue_date?->toDateString(),
                'credential_url' => $certification->credential_url,
            ])->values(),

            'experiences' => $student->experiences->map(fn ($experience) => [
                'id' => $experience->id,
                'organization' => $experience->organization,
                'role' => $experience->role,
                'start_date' => $experience->start_date?->toDateString(),
                'end_date' => $experience->end_date?->toDateString(),
                'description' => $experience->description,
            ])->values(),

            'linkedin_url' => $student->linkedin_url,
            'github_url' => $student->github_url,
            'portfolio_url' => $student->portfolio_url,

            // Metadata only - never a URL or path. The frontend downloads
            // through GET /api/student/profile/resume (authenticated,
            // ownership-scoped), never a direct link built from this data.
            'resume' => $student->resume_path ? [
                'original_name' => $student->resume_original_name,
                'size' => $student->resume_size,
                'uploaded_at' => $student->resume_uploaded_at?->toIso8601String(),
            ] : null,

            'profile_completion' => ProfileCompletionCalculator::calculate($student),
        ];
    }
}
