<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reports & Analytics module - the base, schema-backed filter set
 * shared by every report endpoint (module spec section 12: "filters
 * are schema-backed only - no free-text field accepted as a filter
 * without validation"). Every field is optional, mirroring
 * App\Http\Requests\Admin\ExcelExportFilterRequest's own tolerant
 * convention - a report with no filters returns the full unfiltered
 * institution-wide dataset.
 *
 * `student_id` and `faculty_id` are deliberately NOT accepted as
 * general request filters here for the student/faculty-scoped report
 * endpoints - those two are always the acting user's own resolved
 * Student/Faculty id (see Api\Student\ReportController /
 * Api\Faculty\ReportController), never a client-supplied value, so
 * an IDOR attempt ("give me student_id=42's report") is impossible by
 * construction rather than by an authorization check that could be
 * forgotten. `faculty_id` IS accepted here for the ADMIN-facing
 * endpoints only (Api\Admin\ReportController), where narrowing an
 * institution-wide report to one faculty member's workload is a
 * legitimate admin query, not an ownership bypass.
 */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'batch_id' => ['sometimes', 'nullable', 'integer', 'exists:batches,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1,6'],
            'section' => ['sometimes', 'nullable', 'string', 'max:10'],
            'cgpa_min' => ['sometimes', 'nullable', 'numeric', 'between:0,10'],
            'cgpa_max' => ['sometimes', 'nullable', 'numeric', 'between:0,10', ...($this->filled('cgpa_min') ? ['gte:cgpa_min'] : [])],
            'max_backlogs' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            // none = 0, some = 1-2, many = 3+, not_reported = blank in the college file
            'backlogs' => ['sometimes', 'nullable', Rule::in(['none', 'some', 'many', 'not_reported'])],
            'entry_type' => ['sometimes', 'nullable', Rule::in(['regular', 'lateral'])],
            'year_of_joining' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'faculty_id' => ['sometimes', 'nullable', 'integer', 'exists:faculty,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->validated();
    }
}
