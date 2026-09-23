<?php

namespace App\Http\Requests\InternshipDocument;

use App\Enums\InternshipDocumentStatus;
use App\Enums\InternshipDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-parameter validation for every internship-document index
 * endpoint - GET /api/student/internship-documents, GET
 * /api/faculty/internship-documents, GET /api/admin/internship-documents.
 * One class rather than three nearly identical ones, mirroring
 * WeeklyProgressReportFilterRequest exactly: each controller only reads
 * the filters relevant to its own role, and none of these filters are
 * ever used to widen a caller's own authorization scope - the base
 * query is always scoped to the caller first (see each
 * InternshipDocumentController's docblock), filters applied only as an
 * additional AND on top of that scope. `student_id` is never read by
 * the student controller's own index() (a student's list is always
 * unconditionally their own) - only faculty/admin accept it, per the
 * module spec's "Do not expose student_id as a student-controlled
 * query."
 */
class InternshipDocumentFilterRequest extends FormRequest
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
            'document_type' => ['nullable', Rule::enum(InternshipDocumentType::class)],
            'status' => ['nullable', Rule::enum(InternshipDocumentStatus::class)],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
