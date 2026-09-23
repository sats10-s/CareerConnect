<?php

namespace App\Http\Requests\InternshipCompletion;

use App\Enums\InternshipCompletionOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/faculty/internship-assignments/{assignment}/completion -
 * see Api\Faculty\InternshipCompletionController::store(). There is
 * deliberately no `status` field here at all - a completion record is
 * always created DRAFT server-side
 * (App\Services\InternshipCompletionService::createCompletion()); the
 * only way to reach FINALIZED is the separate finalize() action, never
 * a client-supplied status on create/update.
 *
 * `completion_certificate_document_id`, if supplied, is only checked
 * for basic existence here - whether it actually belongs to THIS
 * assignment is re-validated by
 * InternshipCompletionService::assertCertificateDocumentBelongsToAssignment(),
 * which has access to the resolved $assignment this FormRequest's
 * route parameter alone cannot cleanly express as a `exists:...,where`
 * rule.
 */
class StoreInternshipCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'completion_date' => ['required', 'date', 'before_or_equal:today'],
            'outcome' => ['required', Rule::enum(InternshipCompletionOutcome::class)],
            'final_remarks' => ['nullable', 'string', 'max:2000'],
            'completion_certificate_document_id' => ['nullable', 'integer', 'exists:internship_documents,id'],
        ];
    }
}
