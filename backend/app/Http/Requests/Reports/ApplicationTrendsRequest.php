<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

/**
 * GET /api/admin/reports/trends - time-series application volume.
 * `granularity` is a schema-backed allowlist (day/week/month), never
 * an arbitrary client-supplied date-truncation expression.
 */
class ApplicationTrendsRequest extends ReportFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'granularity' => ['sometimes', 'nullable', Rule::in(['day', 'week', 'month'])],
        ]);
    }

    public function granularity(): string
    {
        return $this->validated('granularity') ?? 'day';
    }
}
