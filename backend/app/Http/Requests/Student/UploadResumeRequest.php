<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class UploadResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `mimes` inspects the file's actual content (via Symfony's
            // content-based mime-type guesser), not the client-supplied
            // filename/extension or Content-Type header - this is the
            // real server-side check the module spec requires. The
            // frontend's `accept=".pdf,.doc,.docx"` is a UX hint only
            // and is never trusted here. `max:5120` is in kilobytes
            // (5120 KB = 5 MB).
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'resume.mimes' => 'Resume must be a PDF, DOC, or DOCX file.',
            'resume.max' => 'Resume must be 5MB or smaller.',
        ];
    }
}
