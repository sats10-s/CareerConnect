<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for GET /api/admin/internship-assignments/{assignment}
 * AND GET /api/faculty/internship-assignments/{assignment} (also every
 * admin write action's response - store/reassignFaculty/activate/
 * complete/cancel). One shared class, gating admin-only fields inline
 * by viewer role - same pattern as PlacementDriveResource (see its
 * docblock).
 *
 * Unlike PlacementDriveResource, `status_history` and
 * `faculty_history` are NOT admin-only here: the module spec's
 * faculty-detail field list explicitly includes both ("assignment
 * history", "faculty assignment history") - a faculty coordinator
 * needs to see who coordinated this student before them, and every
 * status change their assignment has gone through. Only `assigned_by`
 * (which admin created the assignment) and `notes` (an internal admin
 * note about why this faculty/assignment was made) are admin-only,
 * alongside the raw timestamps.
 *
 * The student/internship/company summaries are intentionally light
 * (roll_number/department/year via the student block, and the
 * existing FormatsOpportunitySummary trait for internship+company) -
 * never the student's full profile (cgpa, backlogs, resume, personal
 * links, phone) - see the module spec's "do NOT expose unnecessary
 * student private information".
 *
 * @mixin \App\Models\InternshipAssignment
 */
class InternshipAssignmentResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewerIsAdmin($request);

        $base = [
            'id' => $this->id,
            'status' => $this->status?->value,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),

            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'name' => $this->student->user?->name,
                'email' => $this->student->user?->email,
                'roll_number' => $this->student->roll_number,
                'department' => $this->student->department?->name,
                'batch' => $this->student->batch?->name,
                'year' => $this->student->year,
            ] : null),

            'faculty' => $this->whenLoaded('faculty', fn () => $this->faculty ? [
                'id' => $this->faculty->id,
                'name' => $this->faculty->user?->name,
                'email' => $this->faculty->user?->email,
            ] : null),

            'internship' => $this->whenLoaded('internship', fn () => $this->opportunitySummary($this->internship)),

            'application' => $this->whenLoaded('application', fn () => $this->application ? [
                'id' => $this->application->id,
                'status' => $this->application->status->value,
            ] : null),

            'status_history' => $this->whenLoaded(
                'statusHistory',
                fn () => InternshipAssignmentStatusHistoryResource::collection($this->statusHistory)
            ),
            'faculty_history' => $this->whenLoaded(
                'facultyHistory',
                fn () => InternshipAssignmentFacultyHistoryResource::collection($this->facultyHistory)
            ),
        ];

        if (! $isAdmin) {
            return $base;
        }

        return array_merge($base, [
            'notes' => $this->notes,
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => $this->assignedBy ? [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);
    }

    private function viewerIsAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
