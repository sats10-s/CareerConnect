<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a student editing their own profile (see StudentProfileController).
 * The route is already gated by auth:sanctum + role:STUDENT, so this only
 * validates field shape/uniqueness, not who's allowed to call it.
 *
 * `college_email`/`email` are explicitly `prohibited`: the college email
 * is the immutable login identity (see AuthController/RegistrationService)
 * and must never be changeable from this endpoint. FormRequest::validated()
 * only ever returns keys declared in rules() below, and `email`/
 * `college_email` aren't fillable on the Student model either - so a
 * stray value could never silently reach the database regardless. The
 * `prohibited` rule turns that into a visible, explicit 422 instead of a
 * silent no-op, per the module spec's "reject...or explicitly ignore"
 * requirement - this project takes the reject option.
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
            'college_email' => ['prohibited'],
            'email' => ['prohibited'],

            'roll_number' => ['required', 'string', 'max:32', Rule::unique('students', 'roll_number')->ignore($studentId)],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'year' => ['required', 'integer', 'between:1,4'],
            'cgpa' => ['nullable', 'numeric', 'between:0,10'],
            'backlogs' => ['nullable', 'integer', 'min:0'],
            'phone' => ['nullable', 'string', 'max:20'],

            'personal_email' => ['nullable', 'email', 'max:190'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'github_url' => ['nullable', 'url', 'max:255'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'roll_number.unique' => 'This roll number is already registered to another student.',
            'college_email.prohibited' => 'College email cannot be changed.',
            'email.prohibited' => 'College email cannot be changed.',
        ];
    }
}
