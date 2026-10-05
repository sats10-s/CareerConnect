<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The forced first-login step: a new password (at least 8 characters, not
 * the mobile number or JNTU No - checked in PasswordService) and, for
 * students, a personal email for future password resets.
 */
class FirstLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->must_change_password;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'personal_email' => [$this->user()?->student ? 'required' : 'nullable', 'email', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'personal_email.required' => 'Add a personal email - it is where password reset links are sent.',
        ];
    }
}
