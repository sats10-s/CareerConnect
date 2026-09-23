<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\InternshipAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/faculty/internship-assignments/{assignment}/attendance.
 * Deliberately has NO student_id/faculty_id/internship_assignment_id
 * fields at all - both are always derived from the route-bound
 * InternshipAssignment (see Api\Faculty\AttendanceController::store()
 * and App\Services\AttendanceService::markAttendance()), never
 * accepted as ownership inputs from the client.
 *
 * Field-level rules below cover shape/format only; the business rules
 * that need the assignment's own data (internship-period bounds, no
 * future date, ABSENT/LEAVE-cannot-carry-times, checkout-after-checkin)
 * are ALSO re-checked in withValidator() below AND again in
 * AttendanceService - the same two-layer, defense-in-depth pattern
 * this codebase already uses for duplicate-attendance prevention (see
 * the module spec's section 9 and AttendanceService's docblock) rather
 * than trusting either layer alone.
 */
class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_date' => ['required', 'date'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $assignment = $this->route('assignment');

            if (! $assignment instanceof InternshipAssignment) {
                return;
            }

            $date = $this->input('attendance_date');
            $status = $this->input('status');
            $checkIn = $this->input('check_in_time');
            $checkOut = $this->input('check_out_time');

            if ($date && ! $validator->errors()->has('attendance_date')) {
                $parsed = Carbon::parse($date)->startOfDay();

                if ($parsed->gt(Carbon::today())) {
                    $validator->errors()->add('attendance_date', 'Attendance date cannot be in the future.');
                } elseif ($assignment->started_at === null) {
                    $validator->errors()->add('attendance_date', 'This internship assignment has not started yet; attendance cannot be marked.');
                } elseif ($parsed->lt($assignment->started_at->copy()->startOfDay())) {
                    $validator->errors()->add('attendance_date', sprintf(
                        'Attendance date cannot be before the internship start date (%s).',
                        $assignment->started_at->toDateString(),
                    ));
                } elseif ($assignment->ended_at !== null && $parsed->gt($assignment->ended_at->copy()->startOfDay())) {
                    $validator->errors()->add('attendance_date', sprintf(
                        'Attendance date cannot be after the internship end date (%s).',
                        $assignment->ended_at->toDateString(),
                    ));
                }
            }

            if ($status && ! $validator->errors()->hasAny(['check_in_time', 'check_out_time'])) {
                $enumStatus = AttendanceStatus::tryFrom($status);

                if ($enumStatus !== null && ! $enumStatus->allowsTimes() && ($checkIn !== null || $checkOut !== null)) {
                    $validator->errors()->add('check_in_time', sprintf('Check-in/check-out time cannot be recorded for %s attendance.', $enumStatus->value));
                }
            }

            if ($checkIn && $checkOut && ! $validator->errors()->hasAny(['check_in_time', 'check_out_time'])) {
                if (! Carbon::parse($checkOut)->gt(Carbon::parse($checkIn))) {
                    $validator->errors()->add('check_out_time', 'check_out_time must be later than check_in_time.');
                }
            }
        });
    }
}
