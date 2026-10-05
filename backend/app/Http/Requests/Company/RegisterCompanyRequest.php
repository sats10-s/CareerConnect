<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/company/register - public company self-registration. Creates
 * the company (PENDING) and its single OWNER login; see
 * App\Services\CompanyRegistrationService::register(). Status, source and
 * review fields are never accepted from the client.
 */
class RegisterCompanyRequest extends FormRequest
{
    public const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '501-1000', '1000+'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['company_name', 'industry', 'website', 'description', 'headquarters', 'company_size', 'registration_number', 'contact_name', 'contact_phone'])
        ));

        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'industry' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'headquarters' => ['nullable', 'string', 'max:150'],
            'company_size' => ['nullable', Rule::in(self::COMPANY_SIZES)],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    public function attributes(): array
    {
        return [
            'company_name' => 'company name',
            'contact_name' => 'contact person',
            'contact_phone' => 'contact phone',
            'email' => 'work email',
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.unique' => 'This company is already registered. Contact the placement office if you need access.',
            'email.unique' => 'An account with this email already exists.',
            'contact_phone.regex' => 'Enter a valid phone number.',
        ];
    }
}
