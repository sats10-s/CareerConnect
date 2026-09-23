<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for one weekly progress report - shared by
 * GET /api/student/weekly-reports/{report} and
 * GET /api/faculty/weekly-reports/{report}, and the base every write
 * action responds with. Student/faculty-facing responses go through
 * THIS class with runtime viewer-role gating (see viewerIsAdmin()/
 * viewerIsFaculty()); see AdminWeeklyProgressReportResource for the
 * admin-only subclass that skips that runtime check. Mirrors
 * AttendanceResource exactly.
 *
 * Base fields (report/week information/content/status/faculty
 * feedback/submitted+reviewed timestamps) are always visible - per the
 * module spec's "Student response: report, week information, content,
 * status, faculty feedback, submitted/reviewed timestamps". The
 * `student`/`faculty`/`internship` summary block and `history` are
 * ADDITIONALLY included for a faculty/admin viewer - per "Faculty
 * response additionally includes: student name, roll number, company,
 * internship, assignment information" - a student already knows who
 * they are and does not need it echoed back, and `history` includes
 * `changed_by` (which faculty member reviewed it), not something a
 * student needs to audit.
 *
 * @mixin \App\Models\WeeklyProgressReport
 */
class WeeklyProgressReportResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewerIsAdmin($request);
        $isFaculty = $this->viewerIsFaculty($request);

        $base = [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'week_number' => $this->week_number,
            'week_start_date' => $this->week_start_date?->toDateString(),
            'week_end_date' => $this->week_end_date?->toDateString(),
            'work_done' => $this->work_done,
            'learning_outcomes' => $this->learning_outcomes,
            'challenges' => $this->challenges,
            'next_week_plan' => $this->next_week_plan,
            'status' => $this->status?->value,
            'student_submitted_at' => $this->student_submitted_at?->toIso8601String(),
            'faculty_reviewed_at' => $this->faculty_reviewed_at?->toIso8601String(),
            'faculty_review_remarks' => $this->faculty_review_remarks,
        ];

        if ($isAdmin || $isFaculty) {
            $base = array_merge($base, [
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

                'history' => $this->whenLoaded('statusHistory', fn () => WeeklyProgressReportStatusHistoryResource::collection($this->statusHistory)),
            ]);
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
