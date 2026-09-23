<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates STUDENT self-registration. Faculty / Admin / Super Admin
 * accounts are provisioned by an administrator (seeded for this
 * foundation) rather than through public self-registration.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'roll_number' => ['required', 'string', 'max:32', 'unique:students,roll_number'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'year' => ['required', 'integer', 'between:1,4'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this college email already exists.',
            'roll_number.unique' => 'This roll number is already registered.',
        ];
    }
}
