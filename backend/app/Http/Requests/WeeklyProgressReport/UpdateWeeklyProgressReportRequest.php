<?php

namespace App\Http\Requests\WeeklyProgressReport;

use App\Models\WeeklyProgressReport;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/student/weekly-reports/{report}. Deliberately has NO
 * internship_assignment_id or week_number field at all (and no
 * student_id/faculty_id/status, same as StoreWeeklyProgressReportRequest)
 * - the assignment and the report's week number can never be changed
 * through an ordinary update (see the module spec's section 10); any
 * such field sent by the client is simply not a validated/accepted key
 * and is silently ignored by $request->validated(), never reaching
 * WeeklyProgressReportService::updateDraft().
 *
 * work_done/learning_outcomes/challenges/next_week_plan are 'nullable'
 * here (unlike the 'required' work_done on creation) so an in-progress
 * DRAFT/CHANGES_REQUESTED edit can legitimately blank content back out
 * while composing - WeeklyProgressReportService::submit() is what
 * re-validates that work_done is non-empty before allowing SUBMITTED
 * (see its own docblock), not this request.
 *
 * withValidator() re-checks the same week-validity rules
 * StoreWeeklyProgressReportRequest enforces, against the EFFECTIVE
 * resulting week_start_date/week_end_date (this request's fields
 * merged over the report's existing current values) - mirrors
 * UpdateAttendanceRequest's own "effective merged state" pattern.
 */
class UpdateWeeklyProgressReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'week_start_date' => ['sometimes', 'required', 'date'],
            'week_end_date' => ['sometimes', 'required', 'date'],
            'work_done' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'learning_outcomes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'challenges' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'next_week_plan' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $report = $this->route('report');

            if (! $report instanceof WeeklyProgressReport) {
                return;
            }

            if ($validator->errors()->hasAny(['week_start_date', 'week_end_date'])) {
                return;
            }

            $effectiveStart = $this->has('week_start_date')
                ? Carbon::parse($this->input('week_start_date'))->startOfDay()
                : $report->week_start_date->copy();
            $effectiveEnd = $this->has('week_end_date')
                ? Carbon::parse($this->input('week_end_date'))->startOfDay()
                : $report->week_end_date->copy();

            if (! $effectiveEnd->isSameDay($effectiveStart->copy()->addDays(6))) {
                $validator->errors()->add('week_end_date', 'week_end_date must be exactly 6 days after week_start_date (a 7-day week).');

                return;
            }

            if ($effectiveStart->gt(Carbon::today())) {
                $validator->errors()->add('week_start_date', 'This week has not started yet.');

                return;
            }

            $assignment = $report->internshipAssignment;

            if ($assignment === null || $assignment->started_at === null) {
                return;
            }

            $periodStart = $assignment->started_at->copy()->startOfDay();

            if ($effectiveEnd->lt($periodStart)) {
                $validator->errors()->add('week_start_date', sprintf(
                    'This week falls entirely before the internship start date (%s).',
                    $periodStart->toDateString(),
                ));

                return;
            }

            if ($assignment->ended_at !== null) {
                $periodEnd = $assignment->ended_at->copy()->startOfDay();

                if ($effectiveStart->gt($periodEnd)) {
                    $validator->errors()->add('week_start_date', sprintf(
                        'This week falls entirely after the internship end date (%s).',
                        $periodEnd->toDateString(),
                    ));
                }
            }
        });
    }
}
