<?php

namespace App\Http\Requests\WeeklyProgressReport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/student/weekly-reports/{report}/submit. No request body is
 * accepted at all - a submit action never carries any client-suppliable
 * field (see the module spec's section 11, which lists no request
 * fields for this endpoint); all business validation (required content,
 * valid DRAFT/CHANGES_REQUESTED -> SUBMITTED transition, assignment
 * ACTIVE) happens in App\Services\WeeklyProgressReportService::submit().
 * Kept as its own FormRequest class purely for consistency with this
 * module's other endpoints and as a place to hang authorize()/future
 * validation, rather than accepting a bare Illuminate\Http\Request in
 * the controller.
 */
class SubmitWeeklyProgressReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
