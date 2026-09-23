<?php

namespace App\Http\Requests\Ppo;

use App\Models\InternshipAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/faculty/internship-assignments/{assignment}/ppo - see
 * Api\Faculty\PpoRecordController::update(). Mirrors
 * StoreInternshipCompletionRequest/UpdateInternshipCompletionRequest's
 * own {assignment}-scoped (not {ppoRecord}-scoped) shape exactly, by
 * direct symmetry with the completion route the module spec DID give -
 * see StorePpoRecordRequest's docblock for the full routing rationale.
 * The actual PpoRecord to update is resolved by the controller via
 * `$assignment->ppoRecord` (see App\Models\InternshipAssignment's
 * ppoRecord() relation), never by a separate route-bound id.
 *
 * Every field is `sometimes` (a partial update). `status` may only
 * ever move to ACCEPTED/DECLINED here - never back to OFFERED (a
 * fresh record is always created OFFERED; there is no route back to
 * it) - the transition itself is still re-validated by
 * App\Services\InternshipCompletionService::updatePpo()
 * (PpoStatus::canTransitionTo()), never trusted from this request
 * alone.
 *
 * `joining_date` must fall on/after the EFFECTIVE offered_at - this
 * request's own offered_at if supplied, otherwise the existing
 * record's - checked in withValidator() rather than a plain
 * `after_or_equal:offered_at` rule, since that rule only compares
 * against another field in the SAME request, and offered_at is not
 * always present on a partial update.
 */
class UpdatePpoRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'offered_at' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'joining_date' => ['sometimes', 'nullable', 'date'],
            'designation' => ['sometimes', 'required', 'string', 'max:255'],
            'package' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', Rule::in(['ACCEPTED', 'DECLINED'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['offered_at', 'joining_date']) || ! $this->has('joining_date')) {
                return;
            }

            $joiningDate = $this->input('joining_date');

            if ($joiningDate === null) {
                return;
            }

            $assignment = $this->route('assignment');
            $ppoRecord = $assignment instanceof InternshipAssignment ? $assignment->ppoRecord : null;
            $effectiveOfferedAt = $this->input('offered_at') ?? $ppoRecord?->offered_at?->toDateString();

            if ($effectiveOfferedAt !== null && $joiningDate < $effectiveOfferedAt) {
                $validator->errors()->add('joining_date', 'The joining date must be on or after the offer date.');
            }
        });
    }
}
