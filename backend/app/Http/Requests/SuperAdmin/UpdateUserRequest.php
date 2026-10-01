<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/super-admin/users/{user}. Role changes are limited to moving
 * between Placement Admin and Super Admin (checked in the controller) -
 * students and faculty have profile records tied to their role.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['name', 'email', 'employee_code', 'designation', 'phone'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $value = trim($this->input($key));
                $data[$key] = match ($key) {
                    'email' => strtolower($value),
                    'employee_code' => strtoupper($value),
                    default => $value === '' && in_array($key, ['designation', 'phone'], true) ? null : $value,
                };
            }
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['sometimes', 'required', Rule::in([RoleName::PLACEMENT_ADMIN->value, RoleName::SUPER_ADMIN->value])],
            'employee_code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('faculty', 'employee_code')->ignore($user?->faculty?->id)],
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
            'role.in' => 'Only Placement Admin and Super Admin accounts can switch roles.',
            'employee_code.unique' => 'Another faculty member already has this employee code.',
        ];
    }
}
