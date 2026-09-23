<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an attendance record's append-only creation/correction
 * audit trail. Visible to admin and the assigned faculty (gated one
 * level up in AttendanceResource, mirroring
 * InternshipAssignmentStatusHistoryResource's own precedent) - never
 * to the student.
 *
 * @mixin \App\Models\AttendanceHistory
 */
class AttendanceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'old_status' => $this->old_status?->value,
            'new_status' => $this->new_status->value,
            'old_check_in_time' => $this->old_check_in_time,
            'new_check_in_time' => $this->new_check_in_time,
            'old_check_out_time' => $this->old_check_out_time,
            'new_check_out_time' => $this->new_check_out_time,
            'old_remarks' => $this->old_remarks,
            'new_remarks' => $this->new_remarks,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ] : null),
            'remarks' => $this->remarks,
            'changed_at' => $this->changed_at,
        ];
    }
}
