<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmploymentType;
use App\Enums\OpportunityStatus;
use App\Enums\WorkMode;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a placement admin updating a job posting (see
 * Api\Admin\JobPostingController::update). All fields are `sometimes`
 * (PATCH-style partial update); a `status` transition, when present,
 * is additionally checked against OpportunityStatus::canTransitionTo()
 * in withValidator() below - that check needs the current model
 * (route-bound as {jobPosting}), which is why it can't live in a plain
 * `rules()` array.
 */
class UpdateJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'required', 'integer', 'exists:companies,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:150'],
            'salary_range' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'work_mode' => ['nullable', Rule::enum(WorkMode::class)],
            'application_deadline' => ['nullable', 'date'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $status = $this->input('status');

            /** @var JobPosting|null $jobPosting */
            $jobPosting = $this->route('jobPosting');

            if ($status === null || ! $jobPosting instanceof JobPosting) {
                return;
            }

            $target = OpportunityStatus::from($status);

            if ($jobPosting->status !== $target && ! $jobPosting->status->canTransitionTo($target)) {
                $validator->errors()->add(
                    'status',
                    "Cannot transition a job posting from '{$jobPosting->status->value}' to '{$target->value}'."
                );
            }
        });
    }
}
