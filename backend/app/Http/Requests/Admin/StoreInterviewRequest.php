<?php

namespace App\Http\Requests\Admin;

use App\Enums\InterviewMode;
use App\Http\Requests\Concerns\ValidatesInterviewMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/applications/{application}/interviews (see
 * Api\Admin\InterviewController::store). Route-gated by auth:sanctum +
 * role:PLACEMENT_ADMIN,SUPER_ADMIN, plus InterviewPolicy::create - this
 * class only validates field shape. Application-state eligibility
 * (SHORTLISTED/ASSESSMENT/INTERVIEW only) and opportunity validity are
 * business rules checked in App\Services\InterviewService::create(),
 * not here, since they depend on the {application} route model, not
 * just this request's own payload shape.
 *
 * `scheduled_at` accepts anything Carbon can parse; see
 * InterviewService's docblock for how it's normalized to UTC before
 * being stored.
 */
class StoreInterviewRequest extends FormRequest
{
    use ValidatesInterviewMode;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mode = InterviewMode::tryFrom((string) $this->input('mode'));
        $modeRules = $this->modeSpecificRules($mode);

        return [
            'round' => ['required', 'integer', 'min:1', 'max:50'],
            'title' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'mode' => ['required', Rule::enum(InterviewMode::class)],
            'meeting_url' => $modeRules['meeting_url'],
            'venue' => $modeRules['venue'],
            'interviewer_name' => ['nullable', 'string', 'max:255'],
            'interviewer_email' => ['nullable', 'email', 'max:255'],
            'panel_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
