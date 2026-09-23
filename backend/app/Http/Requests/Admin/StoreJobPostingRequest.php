<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmploymentType;
use App\Enums\OpportunityStatus;
use App\Enums\WorkMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a placement admin creating a job posting (see
 * Api\Admin\JobPostingController::store). The route is already gated
 * by auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN, and the
 * controller additionally calls Gate::authorize('create', ...) via
 * JobPostingPolicy - this class only validates field shape.
 *
 * The optional flat eligibility fields (`eligibility_enforced`,
 * `eligible_department_ids`, `eligible_batch_ids`, `eligible_years`,
 * `minimum_cgpa`, `maximum_backlogs`, `required_skills`) are the
 * admin-facing eligibility-configuration contract; see
 * App\Http\Controllers\Concerns\BuildsEligibilityPayload for how they
 * get translated into JobPosting::syncEligibility()'s internal shape
 * (App\Models\Concerns\HasEligibilityCriteria) - none of them are
 * fillable on JobPosting itself, so the controller strips them out of
 * the create/update payload before calling JobPosting::create()/
 * update().
 */
class StoreJobPostingRequest extends FormRequest
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
            'salary_range' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
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
