<?php

namespace App\Services\Student;

use App\Models\Student;

/**
 * The single source of truth for a student's profile completion - the
 * Profile page, the student dashboard and every profile mutation response
 * all call this, so the number can't drift between screens.
 *
 * Only fields the STUDENT fills in count. College-record fields (name, JNTU
 * No, department, section, CGPA, backlogs, ...) come from the college's own
 * file and are read-only for the student, so counting them would either be
 * a free 100% or a number the student can't move.
 *
 *   LinkedIn       15     Personal email   15
 *   Portfolio      15     Address          10
 *   3+ skills      20     Resume           25
 */
class ProfileCompletionCalculator
{
    public const MIN_SKILLS = 3;

    private const ITEMS = [
        'linkedin' => ['label' => 'LinkedIn profile', 'weight' => 15],
        'portfolio' => ['label' => 'Portfolio link', 'weight' => 15],
        'skills' => ['label' => 'At least 3 skills', 'weight' => 20],
        'personal_email' => ['label' => 'Personal email', 'weight' => 15],
        'address' => ['label' => 'Address', 'weight' => 10],
        'resume' => ['label' => 'Resume', 'weight' => 25],
    ];

    public static function calculate(Student $student): int
    {
        return array_sum(array_map(
            fn (array $item) => $item['done'] ? $item['weight'] : 0,
            self::checklist($student),
        ));
    }

    /**
     * @return list<array{key: string, label: string, weight: int, done: bool}>
     */
    public static function checklist(Student $student): array
    {
        $student->loadMissing('skills');

        $done = [
            'linkedin' => filled($student->linkedin_url),
            'portfolio' => filled($student->portfolio_url),
            'skills' => $student->skills->count() >= self::MIN_SKILLS,
            'personal_email' => filled($student->personal_email),
            'address' => filled($student->address),
            'resume' => filled($student->resume_path),
        ];

        $items = [];
        foreach (self::ITEMS as $key => $item) {
            $items[] = ['key' => $key, 'label' => $item['label'], 'weight' => $item['weight'], 'done' => $done[$key]];
        }

        return $items;
    }
}
