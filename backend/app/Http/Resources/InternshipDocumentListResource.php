<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/student/internship-documents, GET
 * /api/faculty/internship-documents and GET
 * /api/admin/internship-documents - one shared class, mirroring
 * WeeklyProgressReportListResource's own precedent of a single flat
 * shape for every role's list endpoint (nothing here is sensitive
 * enough to need per-role gating: no storage path, no review remarks,
 * no history). Never includes `stored_filename`/`storage_path` - see
 * InternshipDocumentResource's docblock.
 *
 * @mixin \App\Models\InternshipDocument
 */
class InternshipDocumentListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'document_type' => $this->document_type?->value,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'status' => $this->status?->value,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),

            'student' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('student') && $this->internshipAssignment->student ? [
                'id' => $this->internshipAssignment->student->id,
                'name' => $this->internshipAssignment->student->user?->name,
                'roll_number' => $this->internshipAssignment->student->roll_number,
            ] : null),

            'faculty' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('faculty') && $this->internshipAssignment->faculty ? [
                'id' => $this->internshipAssignment->faculty->id,
                'name' => $this->internshipAssignment->faculty->user?->name,
            ] : null),

            'internship' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('internship')
                ? $this->opportunitySummary($this->internshipAssignment->internship)
                : null),
        ];
    }
}
