<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Super Admin > Users row/detail. Never includes the password hash or
 * remember token (hidden on the model); `tokens_count` is the number of
 * active API sessions, when the controller counted them.
 *
 * @mixin \App\Models\User
 */
class SuperAdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->roleName();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            // Placement Admins imported from Excel carry a department.
            'department' => $this->department_id ? $this->department?->name : null,
            'must_change_password' => (bool) $this->must_change_password,
            'is_active' => (bool) $this->is_active,
            'is_self' => $request->user()?->id === $this->id,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tokens_count' => $this->whenCounted('tokens'),
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'roll_number' => $this->student->roll_number,
                'department' => $this->student->department?->name,
                'batch' => $this->student->batch?->name,
                'year' => $this->student->year,
            ] : null),
            'faculty' => $this->whenLoaded('faculty', fn () => $this->faculty ? [
                'id' => $this->faculty->id,
                'employee_code' => $this->faculty->employee_code,
                'department_id' => $this->faculty->department_id,
                'department' => $this->faculty->department?->name,
                'designation' => $this->faculty->designation,
                'phone' => $this->faculty->phone,
            ] : null),
        ];
    }
}
