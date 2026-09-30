<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for GET /api/admin/students. `placement` is derived from the
 * student's applications: `placed` = at least one SELECTED application,
 * `unplaced` = none.
 */
class StudentDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'batch_id' => ['sometimes', 'nullable', 'integer', 'exists:batches,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1,6'],
            'placement' => ['sometimes', 'nullable', Rule::in(['placed', 'unplaced'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['name', 'roll_number', 'cgpa'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
