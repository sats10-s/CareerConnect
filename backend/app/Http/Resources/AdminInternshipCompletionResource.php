<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;

/**
 * Admin detail response for GET
 * /api/admin/internship-completions/{completion} - thin subclass
 * forcing the faculty/admin-only fields (student/faculty/internship/
 * completed_by/certificate_document/history) unconditionally, since
 * the admin controller's context is always-admin by route middleware
 * and doesn't need InternshipCompletionResource's runtime
 * viewerIsAdmin() check. Mirrors AdminInternshipDocumentResource
 * exactly.
 *
 * Declares its own `use FormatsOpportunitySummary;` even though the
 * parent class already does - opportunitySummary() is declared
 * `private` in that trait and is not inherited by a subclass (see
 * AdminInternshipDocumentResource's own docblock for the full
 * explanation).
 */
class AdminInternshipCompletionResource extends InternshipCompletionResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        return array_merge($base, [
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
}
