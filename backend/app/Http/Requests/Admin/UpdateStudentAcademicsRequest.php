<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Update academics" from the Students drawer: a new CGPA and/or backlog
 * count, always with a reason (kept in the student's academic history).
 */
class UpdateStudentAcademicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cgpa' => ['nullable', 'required_without:backlogs', 'numeric', 'between:0,10'],
            'backlogs' => ['nullable', 'required_without:cgpa', 'integer', 'min:0', 'max:99'],
            'semester_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cgpa.required_without' => 'Enter a new CGPA or backlog count.',
            'backlogs.required_without' => 'Enter a new CGPA or backlog count.',
            'reason.required' => 'Say why - it is kept in the student\'s academic history.',
        ];
    }
}
