<?php

namespace App\Http\Requests\Ppo;

use App\Enums\PpoStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-parameter validation for GET
 * /api/faculty/ppo-records and GET /api/admin/ppo-records - mirrors
 * InternshipCompletionFilterRequest exactly. `student_id` is never
 * read by a student-facing endpoint (there is no student index() at
 * all - see StudentPpoRecordController's docblock) - only
 * faculty/admin accept it.
 */
class PpoRecordFilterRequest extends FormRequest
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
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', Rule::enum(PpoStatus::class)],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
