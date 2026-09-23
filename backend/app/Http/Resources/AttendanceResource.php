<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for one attendance record - shared by
 * GET /api/student/attendance/{attendance},
 * GET /api/faculty/attendance/{attendance} (via the assignment-scoped
 * listing) and the base every attendance write action responds with.
 * Student/faculty-facing responses go through THIS class with runtime
 * viewer-role gating (see viewerIsAdmin()); see AdminAttendanceResource
 * for the admin-only subclass that skips that runtime check.
 *
 * The student/faculty/internship+company summaries mirror
 * InternshipAssignmentResource's own light shapes exactly (roll_number/
 * department/year for the student, never cgpa/backlogs/resume/personal
 * links/phone) - see the module spec's "Student response should not
 * expose... other students... faculty internal metadata".
 *
 * `history` is visible to admin and the assigned faculty (their own
 * students' correction trail) but never the student - mirrors
 * InternshipAssignmentResource's status_history/faculty_history
 * precedent of showing history to admin+faculty, deliberately extended
 * here to exclude the student specifically because history includes
 * `changed_by` (which faculty/admin made a correction), not something
 * a student needs to audit.
 *
 * @mixin \App\Models\Attendance
 */
class AttendanceResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewerIsAdmin($request);

        $base = [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status?->value,
            'check_in_time' => $this->check_in_time,
            'check_out_time' => $this->check_out_time,
            'remarks' => $this->remarks,

            'student' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('student') && $this->internshipAssignment->student ? [
                'id' => $this->internshipAssignment->student->id,
                'name' => $this->internshipAssignment->student->user?->name,
                'roll_number' => $this->internshipAssignment->student->roll_number,
                'department' => $this->internshipAssignment->student->department?->name,
                'year' => $this->internshipAssignment->student->year,
            ] : null),

            'faculty' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('faculty') && $this->internshipAssignment->faculty ? [
                'id' => $this->internshipAssignment->faculty->id,
                'name' => $this->internshipAssignment->faculty->user?->name,
            ] : null),

            'internship' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('internship')
                ? $this->opportunitySummary($this->internshipAssignment->internship)
                : null),
        ];

        if ($isAdmin) {
            $base = array_merge($base, [
                'history' => $this->whenLoaded('history', fn () => AttendanceHistoryResource::collection($this->history)),
                'marked_by' => $this->whenLoaded('markedBy', fn () => $this->markedBy ? [
                    'id' => $this->markedBy->id,
                    'name' => $this->markedBy->name,
                ] : null),
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ]);
        } elseif ($this->viewerIsFaculty($request)) {
            $base['history'] = $this->whenLoaded('history', fn () => AttendanceHistoryResource::collection($this->history));
        }

        return $base;
    }

    private function viewerIsAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }

    private function viewerIsFaculty(Request $request): bool
    {
        return $request->user()?->roleName() === RoleName::FACULTY;
    }
}
