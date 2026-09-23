<?php

namespace App\Http\Requests\WeeklyProgressReport;

use App\Models\InternshipAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/student/internship-assignments/{assignment}/weekly-reports.
 * Deliberately has NO student_id/faculty_id/internship_id/company_id/
 * internship_assignment_id/status fields at all - internship_assignment_id
 * is always derived from the route-bound InternshipAssignment (see
 * Api\Student\WeeklyProgressReportController::store() and
 * App\Services\WeeklyProgressReportService::createDraft()), and status
 * always starts at DRAFT server-side - never accepted from the client.
 *
 * Field-level rules below cover shape/format only; the business rules
 * that need the assignment's own data (week number/date consistency,
 * internship-period bounds, no future week) are ALSO re-checked in
 * withValidator() below AND again in WeeklyProgressReportService - the
 * same two-layer, defense-in-depth pattern this codebase already uses
 * for Attendance Management (see StoreAttendanceRequest's docblock)
 * rather than trusting either layer alone.
 */
class StoreWeeklyProgressReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'week_number' => ['required', 'integer', 'min:1'],
            'week_start_date' => ['required', 'date'],
            'week_end_date' => ['required', 'date', 'after_or_equal:week_start_date'],
            'work_done' => ['required', 'string', 'max:10000'],
            'learning_outcomes' => ['nullable', 'string', 'max:5000'],
            'challenges' => ['nullable', 'string', 'max:5000'],
            'next_week_plan' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $assignment = $this->route('assignment');

            if (! $assignment instanceof InternshipAssignment) {
                return;
            }

            if ($validator->errors()->hasAny(['week_start_date', 'week_end_date'])) {
                return;
            }

            $weekStart = $this->input('week_start_date');
            $weekEnd = $this->input('week_end_date');

            if (! $weekStart || ! $weekEnd) {
                return;
            }

            $start = Carbon::parse($weekStart)->startOfDay();
            $end = Carbon::parse($weekEnd)->startOfDay();

            if (! $end->isSameDay($start->copy()->addDays(6))) {
                $validator->errors()->add('week_end_date', 'week_end_date must be exactly 6 days after week_start_date (a 7-day week).');

                return;
            }

            if ($start->gt(Carbon::today())) {
                $validator->errors()->add('week_start_date', 'This week has not started yet.');

                return;
            }

            if ($assignment->started_at === null) {
                $validator->errors()->add('week_start_date', 'This internship assignment has not started yet.');

                return;
            }

            $periodStart = $assignment->started_at->copy()->startOfDay();

            if ($end->lt($periodStart)) {
                $validator->errors()->add('week_start_date', sprintf(
                    'This week falls entirely before the internship start date (%s).',
                    $periodStart->toDateString(),
                ));

                return;
            }

            if ($assignment->ended_at !== null) {
                $periodEnd = $assignment->ended_at->copy()->startOfDay();

                if ($start->gt($periodEnd)) {
                    $validator->errors()->add('week_start_date', sprintf(
                        'This week falls entirely after the internship end date (%s).',
                        $periodEnd->toDateString(),
                    ));
                }
            }
        });
    }
}
