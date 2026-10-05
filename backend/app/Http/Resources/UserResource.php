<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->name,
            'is_active' => $this->is_active,
            'student' => $this->whenLoaded('student', fn () => [
                'roll_number' => $this->student->roll_number,
                'department_id' => $this->student->department_id,
                'department' => $this->student->department?->name,
                'batch_id' => $this->student->batch_id,
                'batch' => $this->student->batch?->name,
                'year' => $this->student->year,
                'cgpa' => $this->student->cgpa === null ? null : (float) $this->student->cgpa,
                'backlogs' => $this->student->backlogs,
                'phone' => $this->student->phone,
            ]),
            'faculty' => $this->whenLoaded('faculty', fn () => [
                'employee_code' => $this->faculty->employee_code,
                'department' => $this->faculty->department?->name,
                'designation' => $this->faculty->designation,
            ]),
            // COMPANY-role logins only: the SPA needs the approval status to
            // decide between the company portal and the pending/rejected page.
            'company' => $this->when($this->company_id !== null, fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'status' => $this->company->status?->value,
                'review_note' => $this->company->review_note,
                'member_role' => $this->company_member_role?->value,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
