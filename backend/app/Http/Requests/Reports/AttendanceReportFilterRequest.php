<?php

namespace App\Http\Requests\Reports;

/**
 * GET /api/admin/reports/attendance[/below-threshold] - adds a single
 * validated `threshold` percentage (0-100 inclusive) on top of the
 * base report filters, used only by the below-threshold view.
 */
class AttendanceReportFilterRequest extends ReportFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'threshold' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
        ]);
    }

    public function threshold(float $default = 75.0): float
    {
        $value = $this->validated('threshold');

        return $value === null ? $default : (float) $value;
    }
}
