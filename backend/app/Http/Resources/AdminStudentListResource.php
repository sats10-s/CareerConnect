<?php

namespace App\Http\Resources;

use App\Enums\ApplicationStatus;
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
            'cgpa' => $this->cgpa === null ? null : (float) $this->cgpa,
            'backlogs' => $this->backlogs,
            'applications_count' => (int) ($this->applications_count ?? 0),
            'selected_count' => (int) ($this->selected_count ?? 0),
            'placement_status' => ($this->selected_count ?? 0) > 0 ? ApplicationStatus::SELECTED->value : null,
        ];
    }
}
