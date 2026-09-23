<?php

namespace App\Http\Requests\Admin;

use App\Enums\OpportunityStatus;
use App\Enums\WorkMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a placement admin creating an internship (see
 * Api\Admin\InternshipController::store). Mirrors
 * StoreJobPostingRequest except for internship-specific fields
 * (duration_months/stipend instead of salary_range/employment_type) -
 * see App\Models\Internship's docblock for why there's no
 * employment_type here.
 */
class StoreInternshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:150'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'stipend' => ['nullable', 'string', 'max:100'],
            'work_mode' => ['nullable', Rule::enum(WorkMode::class)],
            'application_deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'openings' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(OpportunityStatus::class)],
            'is_active' => ['nullable', 'boolean'],

            'eligibility_enforced' => ['nullable', 'boolean'],
            'minimum_cgpa' => ['nullable', 'numeric', 'between:0,10'],
            'maximum_backlogs' => ['nullable', 'integer', 'min:0'],
            'eligible_department_ids' => ['nullable', 'array'],
            'eligible_department_ids.*' => ['integer', 'exists:departments,id'],
            'eligible_batch_ids' => ['nullable', 'array'],
            'eligible_batch_ids.*' => ['integer', 'exists:batches,id'],
            'eligible_years' => ['nullable', 'array'],
            'eligible_years.*' => ['integer', 'between:1,4'],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:100'],
        ];
    }
}
