<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for GET /api/admin/students. `placement` uses the explicit
 * placement status confirmed by the placement office. CGPA range filters never match a student whose CGPA
 * is not on record yet.
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
            'section' => ['sometimes', 'nullable', 'string', 'max:10'],
            // none = 0, some = 1-2, many = 3+, not_reported = blank in the college file
            'backlogs' => ['sometimes', 'nullable', Rule::in(['none', 'some', 'many', 'not_reported'])],
            'cgpa_min' => ['sometimes', 'nullable', 'numeric', 'between:0,10'],
            'cgpa_max' => ['sometimes', 'nullable', 'numeric', 'between:0,10', ...($this->filled('cgpa_min') ? ['gte:cgpa_min'] : [])],
            'entry_type' => ['sometimes', 'nullable', Rule::in(['regular', 'lateral'])],
            'year_of_joining' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'skill' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort' => ['sometimes', 'nullable', Rule::in(['name', 'roll_number', 'cgpa', 'backlogs'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
