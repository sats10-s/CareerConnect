<?php

namespace App\Http\Requests\InternshipDocument;

use App\Enums\InternshipDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/student/internship-documents. Deliberately has NO
 * student_id/faculty_id/internship_assignment_id/status field at all -
 * internship_assignment_id comes from the validated `internship_assignment_id`
 * input (there is no {assignment} route segment for this endpoint per
 * the module spec's exact route list - see
 * Api\Student\InternshipDocumentController::store(), which resolves
 * and authorizes the InternshipAssignment from this field before ever
 * calling the service), and status always starts at SUBMITTED
 * server-side - never accepted from the client.
 *
 * `file` uses the exact same content-based `mimes:pdf,doc,docx` +
 * `max:5120` (5MB) validation as UploadResumeRequest - the pre-existing
 * precedent in this codebase for private document upload. `mimes`
 * inspects the file's actual detected content type, never the client-
 * supplied filename/extension or Content-Type header.
 */
class StoreInternshipDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'internship_assignment_id' => ['required', 'integer', 'exists:internship_assignments,id'],
            'document_type' => ['required', Rule::in(InternshipDocumentType::values())],
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'student_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'The document must be a PDF, DOC, or DOCX file.',
            'file.max' => 'The document must be 5MB or smaller.',
        ];
    }
}
