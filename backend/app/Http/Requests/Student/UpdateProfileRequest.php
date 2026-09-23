<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a student editing their own profile (see StudentProfileController).
 * The route is already gated by auth:sanctum + role:STUDENT, so this only
 * validates field shape/uniqueness, not who's allowed to call it.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Ignore the caller's own student record when checking roll_number
        // uniqueness, otherwise re-submitting the form with an unchanged
        // roll number would always fail validation against itself.
        $studentId = $this->user()?->student?->id;

        return [
            'roll_number' => ['required', 'string', 'max:32', Rule::unique('students', 'roll_number')->ignore($studentId)],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'year' => ['required', 'integer', 'between:1,4'],
            'cgpa' => ['nullable', 'numeric', 'between:0,10'],
            'backlogs' => ['nullable', 'integer', 'min:0'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'roll_number.unique' => 'This roll number is already registered to another student.',
        ];
    }
}
