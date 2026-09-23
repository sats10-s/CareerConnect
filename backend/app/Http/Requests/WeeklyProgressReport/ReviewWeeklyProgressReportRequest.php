<?php

namespace App\Http\Requests\WeeklyProgressReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/faculty/weekly-reports/{report}/review. `action` selects
 * which of App\Services\WeeklyProgressReportService::startReview()/
 * approve()/requestChanges() Api\Faculty\WeeklyProgressReportController::review()
 * calls - the transition itself is still re-validated by the service
 * (canTransitionTo()), never trusted from this request alone.
 *
 * faculty_review_remarks is required (and must be non-blank) when
 * action = CHANGES_REQUESTED - re-checked again in
 * WeeklyProgressReportService's transition() as the load-bearing
 * check, same defense-in-depth pattern as every other request in this
 * module.
 */
class ReviewWeeklyProgressReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['UNDER_REVIEW', 'APPROVED', 'CHANGES_REQUESTED'])],
            'faculty_review_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['action', 'faculty_review_remarks'])) {
                return;
            }

            if ($this->input('action') === 'CHANGES_REQUESTED' && trim((string) $this->input('faculty_review_remarks')) === '') {
                $validator->errors()->add('faculty_review_remarks', 'Review remarks are required when requesting changes.');
            }
        });
    }
}
