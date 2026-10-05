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
    /** False for faculty, who don't see the personal college record. */
    public ?bool $showPersonal = null;

    public function withoutPersonal(): static
    {
        $this->showPersonal = false;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var \App\Models\Student $student */
        $student = $this->student;

        $viewer = $request->user();
        $viewerIsStaff = $viewer !== null && $viewer->id !== $this->id;
        // Faculty see a student's academic, education and "My Profile"
        // cards but not the personal college record (gender, mobile).
        $showPersonal = $this->showPersonal ?? true;

        return [
            'id' => $this->id,
            'name' => $this->name,
            // The staff login identity - imported students have none (they
            // sign in with their JNTU No). Read-only either way.
            'college_email' => $this->email,
            'must_change_password' => (bool) $this->must_change_password,

            // --- College record: the 11 columns of the college's file. ---
            // Read-only for the student (see UpdateProfileRequest).
            'roll_number' => $student->roll_number,
            'year_of_joining' => $student->year_of_joining,
            'gender' => $showPersonal ? $student->gender?->value : null,
            'department_id' => $student->department_id,
            'department' => $student->department?->name,
            'department_code' => $student->department?->code,
            'section' => $student->section,
            'phone' => $showPersonal ? $student->phone : null,
            'cgpa' => $student->cgpa === null ? null : (float) $student->cgpa,
            'backlogs' => $student->backlogs,
            'ssc_score' => $student->ssc_score === null ? null : (float) $student->ssc_score,
            'ssc_scale' => $student->ssc_scale?->value,
            'inter_score' => $student->inter_score === null ? null : (float) $student->inter_score,
            'inter_type' => $student->inter_type?->value,

            // --- Derived from the college record. ---
            'batch_id' => $student->batch_id,
            'batch' => $student->batch?->name,
            'batch_start_year' => $student->batch?->start_year,
            'batch_end_year' => $student->batch?->end_year,
            'year' => $student->year,
            'entry_type' => $student->entry_type?->value,
            'program' => $student->program?->value,
            'academics_updated_at' => $student->academics_updated_at?->toIso8601String(),
            'academic_history' => $student->relationLoaded('academicRecords')
                ? $student->academicRecords->map(fn ($record) => [
                    'id' => $record->id,
                    'cgpa' => $record->cgpa === null ? null : (float) $record->cgpa,
                    'backlogs' => $record->backlogs,
                    'previous_cgpa' => $record->previous_cgpa === null ? null : (float) $record->previous_cgpa,
                    'previous_backlogs' => $record->previous_backlogs,
                    'semester_label' => $record->semester_label,
                    'source' => $record->source?->value,
                    'reason' => $viewerIsStaff ? $record->reason : null,
                    'changed_by' => $viewerIsStaff ? $record->changedBy?->name : null,
                    'created_at' => $record->created_at?->toIso8601String(),
                ])->values()
                : [],

            // --- Student-owned ("My Profile"). ---
            'personal_email' => $student->personal_email,
            'address' => $student->address,

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
            'profile_checklist' => ProfileCompletionCalculator::checklist($student),
        ];
    }
}
