<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/faculty/attendance/{attendance} AND
 * PATCH /api/admin/attendance/{attendance} - shared by both, since the
 * accepted shape and validation are identical; only authorization (see
 * AttendancePolicy::update()) and AttendanceService's
 * $restrictToActiveAssignment flag differ between the two callers.
 *
 * Deliberately has NO attendance_date field (and no
 * internship_assignment_id/student_id/faculty_id, same as
 * StoreAttendanceRequest) - the assignment, student, internship and
 * attendance date can never be changed through an ordinary attendance
 * update (see the module spec's section 10); any such field sent by
 * the client is simply not a validated/accepted key and is silently
 * ignored by $request->validated(), never reaching
 * AttendanceService::updateAttendance().
 *
 * withValidator() re-checks status/time consistency against the
 * EFFECTIVE resulting record (this request's fields merged over the
 * existing attendance row's current values), not just the fields this
 * particular request happens to include - see the analogous comment in
 * AttendanceService::updateAttendance().
 */
class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::enum(AttendanceStatus::class)],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $attendance = $this->route('attendance');

            if (! $attendance instanceof Attendance) {
                return;
            }

            if ($validator->errors()->hasAny(['status', 'check_in_time', 'check_out_time'])) {
                return;
            }

            $effectiveStatus = $this->has('status')
                ? AttendanceStatus::tryFrom($this->input('status'))
                : $attendance->status;

            if ($effectiveStatus === null) {
                return;
            }

            // Only an EXPLICITLY supplied, non-null time alongside a
            // status that disallows times is a genuine contradiction in
            // this request (e.g. {status: "ABSENT", check_in_time:
            // "09:00"}). Switching status to ABSENT/LEAVE without
            // touching the time fields at all is NOT an error here -
            // AttendanceService::updateAttendance() forgivingly clears
            // any pre-existing times itself in that case, so the client
            // is never forced to redundantly null them out in the same
            // request just to change the status.
            $explicitCheckIn = $this->has('check_in_time') ? $this->input('check_in_time') : null;
            $explicitCheckOut = $this->has('check_out_time') ? $this->input('check_out_time') : null;

            if (! $effectiveStatus->allowsTimes() && ($explicitCheckIn !== null || $explicitCheckOut !== null)) {
                $validator->errors()->add('status', sprintf(
                    'Check-in/check-out time cannot be recorded for %s attendance.',
                    $effectiveStatus->value,
                ));

                return;
            }

            $effectiveCheckIn = $this->has('check_in_time') ? $this->input('check_in_time') : $attendance->check_in_time;
            $effectiveCheckOut = $this->has('check_out_time') ? $this->input('check_out_time') : $attendance->check_out_time;

            if ($effectiveStatus->allowsTimes() && $effectiveCheckIn && $effectiveCheckOut
                && ! Carbon::parse($effectiveCheckOut)->gt(Carbon::parse($effectiveCheckIn))) {
                $validator->errors()->add('check_out_time', 'check_out_time must be later than check_in_time.');
            }
        });
    }
}
