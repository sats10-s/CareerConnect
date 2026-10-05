<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sign-in by JNTU No (students) or email (staff). The SPA sends `login`;
 * `email` is still accepted so existing clients keep working. Validation
 * errors are reported under whichever key was sent.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:login', 'nullable', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required_without' => 'Enter your JNTU No or email.',
            'email.required_without' => 'Enter your JNTU No or email.',
        ];
    }

    public function identifier(): string
    {
        return trim((string) ($this->validated('login') ?? $this->validated('email')));
    }

    /** The key validation errors are reported under. */
    public function identifierField(): string
    {
        return $this->filled('login') ? 'login' : 'email';
    }
}
