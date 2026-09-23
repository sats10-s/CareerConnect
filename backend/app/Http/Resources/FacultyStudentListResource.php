<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/faculty/students ("My Students" - see
 * Api\Faculty\StudentController::index). A different shape from
 * InternshipAssignmentListResource because the underlying model here
 * is Student, not InternshipAssignment - the assignment is the SOURCE
 * OF TRUTH for which students appear (no separate faculty-student
 * table exists), but each row is presented as a student, with the
 * coordinating assignment nested underneath it.
 *
 * `assignment` is whichever InternshipAssignment row (scoped to the
 * authenticated faculty) the controller eager-loaded onto
 * `internshipAssignments` - in the overwhelmingly common case a
 * student has exactly one assignment with a given faculty member, but
 * nothing here assumes that; if more than one exists the controller
 * orders them latest-assigned-first and this resource surfaces the
 * first one.
 *
 * Deliberately excludes the student's full profile (cgpa, backlogs,
 * resume, personal links, phone) - only what's useful for a faculty
 * coordination list (see the module spec's "do NOT expose unnecessary
 * student private information").
 *
 * @mixin \App\Models\Student
 */
class FacultyStudentListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $assignment = $this->relationLoaded('internshipAssignments') ? $this->internshipAssignments->first() : null;

        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'roll_number' => $this->roll_number,
            'department' => $this->department?->name,
            'batch' => $this->batch?->name,
            'year' => $this->year,

            'assignment' => $assignment ? [
                'id' => $assignment->id,
                'status' => $assignment->status?->value,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'started_at' => $assignment->started_at?->toIso8601String(),
                'ended_at' => $assignment->ended_at?->toIso8601String(),
                'internship' => $assignment->relationLoaded('internship') ? $this->opportunitySummary($assignment->internship) : null,
            ] : null,
        ];
    }
}
