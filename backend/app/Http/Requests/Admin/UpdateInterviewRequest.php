<?php

namespace App\Http\Requests\Admin;

use App\Enums\InterviewMode;
use App\Http\Requests\Concerns\ValidatesInterviewMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/interviews/{interview} (see
 * Api\Admin\InterviewController::update) - editing an interview's
 * non-scheduling details. `scheduled_at` is explicitly `prohibited`:
 * a scheduled-time change is a reschedule operation with its own
 * status transition and history entry (see
 * POST /api/admin/interviews/{interview}/reschedule /
 * RescheduleInterviewRequest), never a silent field edit here.
 *
 * Every field is `sometimes` (a genuine partial update) EXCEPT
 * meeting_url/venue, whose requirement depends on whether `mode` is
 * present in THIS payload: if the admin isn't changing mode, neither
 * field is required by this request (they may still freely edit
 * either independently); if they ARE changing mode to something that
 * needs a field (ONLINE -> meeting_url, OFFLINE -> venue, HYBRID ->
 * at least one), that field must be supplied in the same request -
 * this avoids ending up with e.g. an ONLINE interview with no
 * meeting_url just because a partial update didn't happen to resend
 * one it already had.
 */
class UpdateInterviewRequest extends FormRequest
{
    use ValidatesInterviewMode;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $modeInput = $this->input('mode');
        $mode = $modeInput !== null ? InterviewMode::tryFrom((string) $modeInput) : null;
        $modeRules = $this->modeSpecificRules($mode);

        return [
            'scheduled_at' => ['prohibited'],
            'round' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:480'],
            'mode' => ['sometimes', Rule::enum(InterviewMode::class)],
            'meeting_url' => $modeRules['meeting_url'],
            'venue' => $modeRules['venue'],
            'interviewer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'interviewer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'panel_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
