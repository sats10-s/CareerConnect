<?php

namespace App\Http\Requests\InternshipCompletion;

use App\Enums\InternshipCompletionOutcome;
use App\Enums\InternshipCompletionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-parameter validation for GET
 * /api/faculty/internship-completions and GET
 * /api/admin/internship-completions - one class rather than two nearly
 * identical ones, mirroring InternshipDocumentFilterRequest exactly:
 * each controller only reads the filters relevant to its own role, and
 * none of these filters are ever used to widen a caller's own
 * authorization scope - the base query is always scoped to the caller
 * first (see each InternshipCompletionController's docblock), filters
 * applied only as an additional AND on top of that scope. `student_id`
 * is never read by a student-facing endpoint (there is no student
 * index() at all - see StudentInternshipCompletionController's
 * docblock) - only faculty/admin accept it.
 */
class InternshipCompletionFilterRequest extends FormRequest
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
            'outcome' => ['nullable', Rule::enum(InternshipCompletionOutcome::class)],
            'status' => ['nullable', Rule::enum(InternshipCompletionStatus::class)],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
