<?php

namespace App\Http\Requests\Student;

use App\Models\StudentSkill;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One skill tag: up to 40 characters, at most 30 per student, and no
 * duplicates regardless of case ("react" and "React" are the same skill).
 * Whitespace is trimmed and collapsed before any of that is checked.
 */
class StoreSkillRequest extends FormRequest
{
    public const MAX_SKILLS = 30;

    public const MAX_LENGTH = 40;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('skill_name'))) {
            $this->merge(['skill_name' => trim((string) preg_replace('/\s+/u', ' ', $this->input('skill_name')))]);
        }
    }

    public function rules(): array
    {
        $studentId = $this->user()?->student?->id;

        return [
            'skill_name' => [
                'required',
                'string',
                'max:'.self::MAX_LENGTH,
                function (string $attribute, mixed $value, Closure $fail) use ($studentId) {
                    $existing = StudentSkill::query()->where('student_id', $studentId)->pluck('skill_name');

                    if ($existing->count() >= self::MAX_SKILLS) {
                        $fail('You can list up to '.self::MAX_SKILLS.' skills. Remove one to add another.');
                    } elseif ($existing->contains(fn ($name) => mb_strtolower($name) === mb_strtolower((string) $value))) {
                        $fail('This skill is already on your profile.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'skill_name.max' => 'Keep each skill to '.self::MAX_LENGTH.' characters.',
        ];
    }
}
