<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/super-admin/users - staff accounts only. Students create their
 * own accounts (registration) or arrive through an Excel Sync import, both
 * of which also create the student record a STUDENT user needs.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'employee_code' => is_string($this->employee_code) ? strtoupper(trim($this->employee_code)) : $this->employee_code,
        ]);
    }

    public function rules(): array
    {
        $isFaculty = $this->input('role') === RoleName::FACULTY->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in([RoleName::FACULTY->value, RoleName::PLACEMENT_ADMIN->value, RoleName::SUPER_ADMIN->value])],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'employee_code' => [Rule::requiredIf($isFaculty), 'nullable', 'string', 'max:32', 'unique:faculty,employee_code'],
            'department_id' => [Rule::requiredIf($isFaculty), 'nullable', 'integer', 'exists:departments,id'],
            'designation' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
            'role.in' => 'Choose Faculty, Placement Admin or Super Admin. Students register themselves or are imported.',
            'employee_code.required' => 'Faculty accounts need an employee code.',
            'employee_code.unique' => 'Another faculty member already has this employee code.',
            'department_id.required' => 'Faculty accounts need a department.',
        ];
    }
}
