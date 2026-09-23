<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'issuing_organization' => ['required', 'string', 'max:150'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'credential_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
