<?php

namespace App\Http\Requests\InternshipDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/faculty/internship-documents/{document}/status. `status`
 * selects which of App\Services\InternshipDocumentService::startReview()/
 * approve()/requestChanges() Api\Faculty\InternshipDocumentController::updateStatus()
 * calls - the transition itself is still re-validated by the service
 * (canTransitionTo()), never trusted from this request alone. Mirrors
 * ReviewWeeklyProgressReportRequest exactly.
 *
 * `faculty_review_remarks` is required (and must be non-blank) when
 * status = CHANGES_REQUESTED - re-checked again in
 * InternshipDocumentService's transition() as the load-bearing check.
 */
class ReviewInternshipDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['UNDER_REVIEW', 'APPROVED', 'CHANGES_REQUESTED'])],
            'faculty_review_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['status', 'faculty_review_remarks'])) {
                return;
            }

            if ($this->input('status') === 'CHANGES_REQUESTED' && trim((string) $this->input('faculty_review_remarks')) === '') {
                $validator->errors()->add('faculty_review_remarks', 'Review remarks are required when requesting changes.');
            }
        });
    }
}
