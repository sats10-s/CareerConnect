<?php

namespace App\Http\Requests\InternshipDocument;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/student/internship-documents/{document}/resubmit.
 * Deliberately has NO document_type/title/internship_assignment_id/
 * status field at all - a resubmission only ever replaces the file
 * (and optionally the student_notes) of an EXISTING document; its
 * document_type/title/assignment can never change through this action
 * (see App\Services\InternshipDocumentService::resubmit(), which never
 * accepts or writes either), mirroring how
 * UpdateWeeklyProgressReportRequest never accepts
 * internship_assignment_id/week_number.
 *
 * Whether this is even allowed at all (only while
 * status.isResubmittable(), i.e. CHANGES_REQUESTED) is re-checked
 * authoritatively in the service, not here - this request only
 * validates shape.
 */
class ResubmitInternshipDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'student_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
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
