<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/company/profile - a recruiter editing their own company's
 * profile (allowed while PENDING too). Partial update. The company is
 * always the authenticated user's own; no company id is read from the
 * request, and status/source/review fields are not editable here.
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['name', 'industry', 'website', 'description', 'headquarters', 'company_size', 'registration_number', 'contact_name', 'contact_email', 'contact_phone'])
        ));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($this->user()->company_id)],
            'industry' => ['sometimes', 'nullable', 'string', 'max:100'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'headquarters' => ['sometimes', 'nullable', 'string', 'max:150'],
            'company_size' => ['sometimes', 'nullable', Rule::in(RegisterCompanyRequest::COMPANY_SIZES)],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contact_name' => ['sometimes', 'required', 'string', 'max:150'],
            'contact_email' => ['sometimes', 'required', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'company name', 'contact_name' => 'contact person'];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A company with this name already exists.',
            'contact_phone.regex' => 'Enter a valid phone number.',
        ];
    }
}
