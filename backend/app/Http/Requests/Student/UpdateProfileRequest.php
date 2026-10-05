<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A student editing their own "My Profile" fields (see
 * StudentProfileController). Only student-owned fields are accepted:
 * personal email, address, LinkedIn, portfolio and GitHub. Skills and the
 * resume have their own endpoints.
 *
 * Every college-record field (the 11 columns of the college's student file,
 * plus what is derived from them) is `prohibited`: it comes from the Super
 * Admin's import and the student can only view it. Sending one is a
 * visible 422, never a silent no-op - the "Report a correction" button is
 * the student's route for a wrong value.
 *
 * All editable fields are `sometimes`, so a PATCH can update just one.
 */
class UpdateProfileRequest extends FormRequest
{
    /** College-record fields a student may never write. */
    public const COLLEGE_FIELDS = [
        'name', 'college_email', 'email',
        'roll_number', 'year_of_joining', 'gender', 'department_id', 'department', 'section',
        'phone', 'mobile', 'cgpa', 'backlogs', 'ssc_score', 'ssc_scale', 'inter_score', 'inter_type',
        'batch_id', 'year', 'entry_type', 'program', 'academics_updated_at',
    ];

    public const LINKEDIN_PATTERN = '~^https://(www\.)?linkedin\.com/in/[^/\s?#]+/?(\?\S*)?$~i';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];
        foreach (self::COLLEGE_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules + [
            'personal_email' => ['sometimes', 'required', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'linkedin_url' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:'.self::LINKEDIN_PATTERN],
            'portfolio_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'github_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $messages = [
            'linkedin_url.regex' => 'Use your LinkedIn profile link, like https://www.linkedin.com/in/your-name.',
            'portfolio_url.url' => 'Use a full https:// link.',
            'github_url.url' => 'Use a full https:// link.',
            'personal_email.required' => 'Your personal email is needed to reset your password.',
        ];

        foreach (self::COLLEGE_FIELDS as $field) {
            $messages["{$field}.prohibited"] = 'This comes from your college records and can\'t be changed here. Use "Report a correction" if it\'s wrong.';
        }

        return $messages;
    }

    /** Trims, and turns empty strings into null so "clear this field" works. */
    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['personal_email', 'address', 'linkedin_url', 'portfolio_url', 'github_url'] as $field) {
            if ($this->has($field)) {
                $value = is_string($this->input($field)) ? trim($this->input($field)) : $this->input($field);
                $clean[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($clean);
    }
}
