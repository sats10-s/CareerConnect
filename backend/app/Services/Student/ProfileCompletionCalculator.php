<?php

namespace App\Services\Student;

use App\Models\Student;

/**
 * The single source of truth for the Student Profile's completion
 * percentage. Every endpoint that returns a profile
 * (StudentProfileResource) calls this, so the number can never drift
 * between screens or between the list/detail/mutation responses.
 *
 * Deliberately separate from StudentDashboardController's own
 * profile_completion calculation (a simpler 6-field ratio used on the
 * dashboard) - that's existing dashboard business logic this module
 * does not touch, even though the two numbers may now differ.
 *
 * Weights (sum to 100, fixed by the Student Profile module spec):
 *   Personal information   15%   Certifications          10%
 *   Academic information   15%   Experience              10%
 *   Skills                 10%   Professional links      15%
 *   Projects               15%   Resume                  10%
 */
class ProfileCompletionCalculator
{
    private const WEIGHTS = [
        'personal' => 15,
        'academic' => 15,
        'skills' => 10,
        'projects' => 15,
        'certifications' => 10,
        'experience' => 10,
        'links' => 15,
        'resume' => 10,
    ];

    public static function calculate(Student $student): int
    {
        $student->loadMissing(['user', 'skills', 'projects', 'certifications', 'experiences']);

        $score = 0;

        if (self::personalComplete($student)) {
            $score += self::WEIGHTS['personal'];
        }
        if (self::academicComplete($student)) {
            $score += self::WEIGHTS['academic'];
        }
        if ($student->skills->isNotEmpty()) {
            $score += self::WEIGHTS['skills'];
        }
        if ($student->projects->isNotEmpty()) {
            $score += self::WEIGHTS['projects'];
        }
        if ($student->certifications->isNotEmpty()) {
            $score += self::WEIGHTS['certifications'];
        }
        if ($student->experiences->isNotEmpty()) {
            $score += self::WEIGHTS['experience'];
        }
        if (self::linksComplete($student)) {
            $score += self::WEIGHTS['links'];
        }
        if (filled($student->resume_path)) {
            $score += self::WEIGHTS['resume'];
        }

        return $score;
    }

    /**
     * Complete when: full name, college email, personal email, phone.
     */
    private static function personalComplete(Student $student): bool
    {
        return filled($student->user?->name)
            && filled($student->user?->email)
            && filled($student->personal_email)
            && filled($student->phone);
    }

    /**
     * Complete when: roll number, department, year, batch, CGPA, backlogs.
     * Backlogs uses !== null (not `filled`) because 0 is a valid,
     * meaningful "complete" value that `filled()` would treat as empty.
     */
    private static function academicComplete(Student $student): bool
    {
        return filled($student->roll_number)
            && filled($student->department_id)
            && filled($student->year)
            && filled($student->batch_id)
            && $student->cgpa !== null
            && $student->backlogs !== null;
    }

    /**
     * Complete only when ALL THREE links are present - a partial set
     * (e.g. just LinkedIn) does not earn any of this weight.
     */
    private static function linksComplete(Student $student): bool
    {
        return filled($student->linkedin_url)
            && filled($student->github_url)
            && filled($student->portfolio_url);
    }
}
