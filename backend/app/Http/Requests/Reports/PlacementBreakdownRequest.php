<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

/**
 * GET /api/admin/reports/placements/breakdown - the institution-level
 * placement report broken down by one dimension. `dimension` and
 * `sort` are both schema-backed allowlists (module spec section 12) -
 * never an arbitrary client-supplied column name passed straight into
 * an ORDER BY clause.
 */
class PlacementBreakdownRequest extends ReportFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'dimension' => ['sometimes', 'nullable', Rule::in(['company', 'department', 'batch'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['label', 'total_applications', 'distinct_students', 'students_selected', 'selection_rate'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    public function dimension(): string
    {
        return $this->validated('dimension') ?? 'company';
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'label';
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'asc';
    }
}
