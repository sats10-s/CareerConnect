<?php

namespace App\Http\Requests\Admin;

use App\Enums\OpportunityStatus;
use App\Enums\WorkMode;
use App\Models\Internship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a placement admin updating an internship (see
 * Api\Admin\InternshipController::update). Mirrors
 * UpdateJobPostingRequest's status-transition check - see that class
 * for why it lives in withValidator() rather than rules().
 */
class UpdateInternshipRequest extends FormRequest
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
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'stipend' => ['nullable', 'string', 'max:100'],
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

            /** @var Internship|null $internship */
            $internship = $this->route('internship');

            if ($status === null || ! $internship instanceof Internship) {
                return;
            }

            $target = OpportunityStatus::from($status);

            if ($internship->status !== $target && ! $internship->status->canTransitionTo($target)) {
                $validator->errors()->add(
                    'status',
                    "Cannot transition an internship from '{$internship->status->value}' to '{$target->value}'."
                );
            }
        });
    }
}
