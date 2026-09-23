<?php

namespace App\Http\Requests\Admin;

use App\Enums\InterviewMode;
use App\Http\Requests\Concerns\ValidatesInterviewMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/interviews/{interview}/reschedule (see
 * Api\Admin\InterviewController::reschedule /
 * App\Services\InterviewService::reschedule()). `scheduled_at` is the
 * one required field; duration_minutes/mode/meeting_url/venue are all
 * optional - omitted ones are left exactly as they were (see
 * InterviewService::reschedule()'s "only touch keys present in $data"
 * handling). `remarks` is an optional admin-supplied reason folded
 * into the resulting interview_status_history row alongside the
 * auto-generated "rescheduled from X to Y" note.
 */
class RescheduleInterviewRequest extends FormRequest
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
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'mode' => ['nullable', Rule::enum(InterviewMode::class)],
            'meeting_url' => $modeRules['meeting_url'],
            'venue' => $modeRules['venue'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
