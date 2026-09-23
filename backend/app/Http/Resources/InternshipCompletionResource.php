<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for one internship completion record - shared by
 * GET /api/faculty/internship-completions/{completion} and the base
 * every write action responds with. Student-facing responses
 * (GET /api/student/internship-completion) also go through THIS class
 * with runtime viewer-role gating (see viewerIsAdmin()/
 * viewerIsFaculty()); see AdminInternshipCompletionResource for the
 * admin-only subclass that skips that runtime check. Mirrors
 * InternshipDocumentResource exactly.
 *
 * Base fields (completion_date/outcome/final_remarks/status/
 * finalized_at) are always visible - a student is entitled to see
 * their own final assessment in full. The `student`/`faculty`/
 * `internship`/`certificate_document`/`history` summary block is
 * ADDITIONALLY included for a faculty/admin viewer - a student already
 * knows who they are and does not need it echoed back.
 *
 * @mixin \App\Models\InternshipCompletion
 */
class InternshipCompletionResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewerIsAdmin($request);
        $isFaculty = $this->viewerIsFaculty($request);

        $base = [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'completion_date' => $this->completion_date?->toDateString(),
            'outcome' => $this->outcome?->value,
            'final_remarks' => $this->final_remarks,
            'completion_certificate_document_id' => $this->completion_certificate_document_id,
            'status' => $this->status?->value,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
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

                'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy ? [
                    'id' => $this->completedBy->id,
                    'name' => $this->completedBy->name,
                ] : null),

                'certificate_document' => $this->whenLoaded('completionCertificateDocument', fn () => $this->completionCertificateDocument ? [
                    'id' => $this->completionCertificateDocument->id,
                    'title' => $this->completionCertificateDocument->title,
                    'document_type' => $this->completionCertificateDocument->document_type?->value,
                ] : null),

                'history' => $this->whenLoaded('statusHistory', fn () => InternshipCompletionStatusHistoryResource::collection($this->statusHistory)),
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
