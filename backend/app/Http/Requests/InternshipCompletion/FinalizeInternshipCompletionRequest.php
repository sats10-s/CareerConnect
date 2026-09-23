<?php

namespace App\Http\Requests\InternshipCompletion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/faculty/internship-completions/{completion}/finalize - see
 * Api\Faculty\InternshipCompletionController::finalize(). Mirrors
 * CompleteInternshipAssignmentRequest - no required fields;
 * `final_remarks` is an optional override folded into the finalize
 * action itself (see
 * InternshipCompletionService::finalizeCompletion()'s docblock), never
 * required - a faculty member can finalize a record exactly as
 * previously drafted.
 *
 * NOTE ON ROUTING: the module spec's own route list (section 6) gives
 * no dedicated finalize route, only POST/PATCH .../completion (create/
 * update) and the two GET routes - but section 4 explicitly requires a
 * "finalize completion" service action and section 5 requires
 * "Finalized completion is immutable," which together need SOME
 * client-reachable action to move DRAFT -> FINALIZED. This route
 * (POST /api/faculty/internship-completions/{completion}/finalize) is
 * a reasoned addition, chosen because it fits directly alongside the
 * two GET routes the spec DID give for /api/faculty/internship-completions,
 * and mirrors InternshipDocumentController's own `/status` sub-action
 * pattern - deliberately NOT folded into update() (see
 * StoreInternshipCompletionRequest's docblock for why a completion's
 * own record status is never settable via the plain create/update
 * fields at all).
 */
class FinalizeInternshipCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'final_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
