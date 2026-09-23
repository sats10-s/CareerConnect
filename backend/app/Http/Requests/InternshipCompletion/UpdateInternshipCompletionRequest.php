<?php

namespace App\Http\Requests\InternshipCompletion;

use App\Enums\InternshipCompletionOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/faculty/internship-assignments/{assignment}/completion -
 * see Api\Faculty\InternshipCompletionController::update(). Every
 * field is `sometimes` (a partial update); `status` is never accepted
 * here either - see StoreInternshipCompletionRequest's docblock for
 * why. Whether the target completion record is still editable at all
 * (not FINALIZED) is enforced by
 * App\Policies\InternshipCompletionPolicy::update() and re-checked as
 * the load-bearing rule in
 * InternshipCompletionService::updateCompletion() - this request only
 * validates shape, never business state.
 */
class UpdateInternshipCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'completion_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'outcome' => ['sometimes', 'required', Rule::enum(InternshipCompletionOutcome::class)],
            'final_remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'completion_certificate_document_id' => ['sometimes', 'nullable', 'integer', 'exists:internship_documents,id'],
        ];
    }
}
