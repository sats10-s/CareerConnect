<?php

namespace App\Http\Requests\WeeklyProgressReport;

use App\Enums\WeeklyReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-parameter validation for every weekly-report index
 * endpoint - GET /api/student/weekly-reports, GET
 * /api/faculty/weekly-reports, GET /api/admin/weekly-reports. One
 * class rather than three nearly identical ones, mirroring
 * AttendanceFilterRequest exactly: each controller only reads the
 * filters relevant to its own role, and none of these filters are ever
 * used to widen a caller's own authorization scope - see each
 * WeeklyProgressReportController's docblock (the base query is always
 * scoped to the caller first, filters applied only as an additional
 * AND on top of that scope).
 */
class WeeklyProgressReportFilterRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(WeeklyReportStatus::class)],
            'week_number' => ['nullable', 'integer', 'min:1'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
