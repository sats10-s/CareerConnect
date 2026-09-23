<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $studentId = $this->user()?->student?->id;

        return [
            'skill_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('student_skills', 'skill_name')->where('student_id', $studentId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'skill_name.unique' => 'This skill is already on your profile.',
        ];
    }
}
