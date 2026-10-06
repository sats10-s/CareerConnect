<?php

namespace App\Http\Requests\Company;

use App\Enums\EmploymentType;
use App\Enums\WorkMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/company/postings and PATCH /api/company/postings/{type}/{id}.
 * Field shape only - the company comes from the signed-in recruiter and the
 * status from CompanyPostingService. Status, company_id, source and every
 * eligibility field are deliberately absent: eligibility stays with the
 * placement office (see Admin\JobPostingController).
 */
class CompanyPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function type(): string
    {
        return (string) ($this->route('type') ?? $this->input('type'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['title', 'description', 'location', 'salary_range', 'stipend'])
        ));
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'work_mode' => ['sometimes', 'nullable', Rule::enum(WorkMode::class)],
            'application_deadline' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'openings' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ];

        if ($creating) {
            $rules['type'] = ['required', Rule::in(['job', 'internship'])];
        }

        return $rules + match ($this->type()) {
            'internship' => [
                'duration_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60'],
                'stipend' => ['sometimes', 'nullable', 'string', 'max:100'],
            ],
            default => [
                'salary_range' => ['sometimes', 'nullable', 'string', 'max:100'],
                'employment_type' => ['sometimes', 'nullable', Rule::enum(EmploymentType::class)],
            ],
        };
    }

    /** Validated posting fields, without the `type` discriminator. */
    public function postingData(): array
    {
        return collect($this->validated())->except('type')->all();
    }
}
