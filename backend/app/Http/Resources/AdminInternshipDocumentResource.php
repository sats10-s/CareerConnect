<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;

/**
 * Admin detail response for GET /api/admin/internship-documents/{document} -
 * thin subclass forcing the faculty/admin-only fields (student/
 * faculty/internship/uploaded_by/history) unconditionally, since the
 * admin controller's context is always-admin by route middleware and
 * doesn't need InternshipDocumentResource's runtime viewerIsAdmin()
 * check. Mirrors AdminWeeklyProgressReportResource exactly.
 *
 * Declares its own `use FormatsOpportunitySummary;` even though the
 * parent class already does - opportunitySummary() is declared
 * `private` in that trait, and PHP does not make a private
 * (trait-provided or otherwise) method callable from a subclass, only
 * from the exact class it was compiled into (this bit
 * AdminWeeklyProgressReportResource earlier in this project - see its
 * own docblock for the full explanation - so it's applied here from
 * the start rather than being discovered as a bug later).
 */
class AdminInternshipDocumentResource extends InternshipDocumentResource
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

            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy ? [
                'id' => $this->uploadedBy->id,
                'name' => $this->uploadedBy->name,
            ] : null),

            'history' => $this->whenLoaded('statusHistory', fn () => InternshipDocumentStatusHistoryResource::collection($this->statusHistory)),
        ]);
    }
}
