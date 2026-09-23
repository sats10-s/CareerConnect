<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for one internship document - shared by
 * GET /api/student/internship-documents/{document} and
 * GET /api/faculty/internship-documents/{document}, and the base every
 * write action responds with. Student/faculty-facing responses go
 * through THIS class with runtime viewer-role gating (see
 * viewerIsAdmin()/viewerIsFaculty()); see AdminInternshipDocumentResource
 * for the admin-only subclass that skips that runtime check. Mirrors
 * WeeklyProgressReportResource exactly.
 *
 * SECURITY - `stored_filename`/`storage_path` are NEVER included here
 * (or anywhere else in this module's Resources), per the module spec's
 * explicit "Do NOT expose: stored_filename, storage_path, filesystem
 * paths, internal storage identifiers" - a client can only ever
 * retrieve the file's bytes through the authenticated `/download`
 * endpoint (see App\Services\InternshipDocumentService::retrieveForDownload()),
 * never by reading a path out of a JSON response.
 *
 * Base fields (document/file metadata/status/student notes/faculty
 * feedback/lifecycle timestamps) are always visible - per the module
 * spec's "Student resource should expose ... status, student_notes,
 * faculty_review_remarks where appropriate". The `student`/`faculty`/
 * `internship`/`history` summary block is ADDITIONALLY included for a
 * faculty/admin viewer - a student already knows who they are and does
 * not need it echoed back.
 *
 * @mixin \App\Models\InternshipDocument
 */
class InternshipDocumentResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewerIsAdmin($request);
        $isFaculty = $this->viewerIsFaculty($request);

        $base = [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'document_type' => $this->document_type?->value,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'status' => $this->status?->value,
            'student_notes' => $this->student_notes,
            'faculty_review_remarks' => $this->faculty_review_remarks,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'changes_requested_at' => $this->changes_requested_at?->toIso8601String(),
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

                'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy ? [
                    'id' => $this->uploadedBy->id,
                    'name' => $this->uploadedBy->name,
                ] : null),

                'history' => $this->whenLoaded('statusHistory', fn () => InternshipDocumentStatusHistoryResource::collection($this->statusHistory)),
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
