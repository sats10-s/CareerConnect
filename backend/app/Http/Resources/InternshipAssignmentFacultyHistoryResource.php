<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an internship assignment's append-only FACULTY
 * REASSIGNMENT audit trail - see
 * internship_assignment_faculty_history's migration docblock for why
 * this is a dedicated table/resource rather than folded into status
 * history. Visible to BOTH the admin and the assigned faculty member
 * (see the module spec's faculty-detail field list, which explicitly
 * includes "faculty assignment history").
 *
 * @mixin \App\Models\InternshipAssignmentFacultyHistory
 */
class InternshipAssignmentFacultyHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_faculty' => $this->whenLoaded('fromFaculty', fn () => $this->fromFaculty ? [
                'id' => $this->fromFaculty->id,
                'name' => $this->fromFaculty->user?->name,
            ] : null),
            'to_faculty' => $this->whenLoaded('toFaculty', fn () => $this->toFaculty ? [
                'id' => $this->toFaculty->id,
                'name' => $this->toFaculty->user?->name,
            ] : null),
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ] : null),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
