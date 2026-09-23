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
 * The optional `eligibility` sub-payload is the normalized criteria
 * persisted via JobPosting::syncEligibility() (see
 * App\Models\Concerns\HasEligibilityCriteria) - it is intentionally
 * NOT flattened into top-level fields so it can be shared verbatim
 * with StoreInternshipRequest/UpdateJobPostingRequest/
 * UpdateInternshipRequest.
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

            'eligibility' => ['nullable', 'array'],
            'eligibility.min_cgpa' => ['nullable', 'numeric', 'between:0,10'],
            'eligibility.max_backlogs' => ['nullable', 'integer', 'min:0'],
            'eligibility.department_ids' => ['nullable', 'array'],
            'eligibility.department_ids.*' => ['integer', 'exists:departments,id'],
            'eligibility.batch_ids' => ['nullable', 'array'],
            'eligibility.batch_ids.*' => ['integer', 'exists:batches,id'],
            'eligibility.years' => ['nullable', 'array'],
            'eligibility.years.*' => ['integer', 'between:1,4'],
            'eligibility.skills' => ['nullable', 'array'],
            'eligibility.skills.*' => ['string', 'max:100'],
        ];
    }
}
