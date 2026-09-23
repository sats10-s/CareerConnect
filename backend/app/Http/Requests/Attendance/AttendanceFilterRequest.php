<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-parameter validation for every attendance index
 * endpoint - GET /api/student/attendance, GET /api/faculty/attendance,
 * GET /api/admin/attendance. One class rather than three nearly
 * identical ones: each controller only reads the filters relevant to
 * its own role (e.g. the student controller never reads faculty_id),
 * so the unused fields for a given caller are simply ignored rather
 * than rejected - none of these filters are ever used to widen a
 * caller's own authorization scope (see each AttendanceController's
 * docblock: the base query is always scoped to the caller first, and
 * these filters are applied only as an additional AND on top of that
 * scope).
 */
class AttendanceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'internship_assignment_id' => ['nullable', 'integer', 'exists:internship_assignments,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'faculty_id' => ['nullable', 'integer', 'exists:faculty,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'internship_id' => ['nullable', 'integer', 'exists:internships,id'],
            'status' => ['nullable', Rule::enum(AttendanceStatus::class)],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
