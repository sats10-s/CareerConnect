<?php

namespace App\Http\Requests\Admin;

use App\Services\ExcelExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/excel/exports/{type}. Every field is optional - an
 * export with no filters at all returns the full unfiltered dataset
 * (module spec sections 19/26: "filterable by academic year, batch,
 * department, ... company, job, ... status, date range, faculty").
 * App\Services\ExcelExportService's own per-type docblocks say exactly
 * which of these a given export type actually applies; an
 * inapplicable filter here is simply ignored there rather than
 * rejected - the same tolerant behaviour every list endpoint in this
 * codebase already has for a query parameter it doesn't recognise.
 */
class ExcelExportFilterRequest extends FormRequest
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
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'faculty_id' => ['sometimes', 'nullable', 'integer', 'exists:faculty,id'],
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Student-level outcome for exports that list students (see
            // ExcelExportService::applyStudentOutcome()).
            'student_status' => ['sometimes', 'nullable', Rule::in(ExcelExportService::STUDENT_STATUSES)],
            'applicable_type' => ['sometimes', 'nullable', Rule::in(['job', 'internship'])],
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
