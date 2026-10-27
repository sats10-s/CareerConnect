<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/admin/students. `applications_count` and
 * `selected_count` come from the controller's withCount() calls.
 *
 * @mixin \App\Models\Student
 */
class AdminStudentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'is_active' => (bool) $this->user?->is_active,
            'roll_number' => $this->roll_number,
            'department' => $this->department?->name,
            'batch' => $this->batch?->name,
            'year' => $this->year,
            'section' => $this->section,
            'entry_type' => $this->entry_type?->value,
            'program' => $this->program?->value,
            'year_of_joining' => $this->year_of_joining,
            'cgpa' => $this->cgpa === null ? null : (float) $this->cgpa,
            'backlogs' => $this->backlogs,
            'applications_count' => (int) ($this->applications_count ?? 0),
            'selected_count' => (int) ($this->selected_count ?? 0),
            'placement_status' => $this->placement_status,
            'placement_company' => $this->placement_company,
            'placement_job_role' => $this->placement_job_role,
            'placement_package' => $this->placement_package === null ? null : (float) $this->placement_package,
            'placement_date' => $this->placement_date?->toDateString(),
            'placement_status_updated_by' => $this->placement_status_updated_by,
            'placement_status_updated_at' => $this->placement_status_updated_at?->toIso8601String(),
        ];
    }
}
