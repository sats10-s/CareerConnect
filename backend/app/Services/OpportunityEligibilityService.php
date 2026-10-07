<?php

namespace App\Services;

use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Student;

/**
 * Deterministic, rule-based evaluation of a student's eligibility
 * against an opportunity's admin-configured criteria. No AI, no
 * autonomous decision-making, and no independent judgment of who
 * "should" get an opportunity - it only checks the student's profile
 * against exactly what a PLACEMENT_ADMIN/SUPER_ADMIN configured via
 * HasEligibilityCriteria::syncEligibility(), one dimension at a time.
 *
 * Callers (currently Api\Student\OpportunityController; later the
 * Application module) always derive $student from the authenticated
 * user - never from a client-supplied id - so this service never
 * trusts an externally-given student identity either; it just takes
 * whatever Student model it's handed.
 *
 * IMPORTANT product rule: this service never hides an opportunity and
 * is never consulted by the student opportunity listing
 * (GET /api/student/opportunities) - every published opportunity stays
 * visible to every student regardless of what evaluate() would return.
 * It exists purely to report status/reasons (and, later, to gate
 * applying when eligibility_enforced is true).
 */
class OpportunityEligibilityService
{
    /**
     * @return array{eligibility_enforced: bool, eligible: bool, reasons: array<int, array{code: string, message: string}>}
     */
    public function evaluate(Student $student, JobPosting|Internship $opportunity): array
    {
        $criteria = $opportunity->eligibilityCriteria;
        $enforced = (bool) ($criteria?->eligibility_enforced ?? false);

        // Per the product rule: when the admin hasn't turned
        // enforcement on, the student is reported eligible with no
        // reasons - "eligible to apply" - regardless of whether their
        // profile would actually satisfy the (still purely
        // informational) configured criteria.
        if (! $enforced) {
            return [
                'eligibility_enforced' => false,
                'eligible' => true,
                'reasons' => [],
            ];
        }

        $reasons = $this->criteriaReasons($student, $opportunity);

        return [
            'eligibility_enforced' => true,
            'eligible' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * Every configured criterion the student fails, whether or not the
     * admin has switched enforcement on - used by evaluate() above and
     * by the Eligible Students Excel export, which reports against the
     * criteria as configured. An empty array means the student meets
     * all of them (or none are configured).
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function criteriaReasons(Student $student, JobPosting|Internship $opportunity): array
    {
        $criteria = $opportunity->eligibilityCriteria;
        $reasons = [];

        $departmentIds = $opportunity->eligibleDepartments->pluck('department_id');
        if ($departmentIds->isNotEmpty()
            && (! $student->department_id || ! $departmentIds->contains($student->department_id))) {
            $reasons[] = [
                'code' => 'DEPARTMENT',
                'message' => 'Your department is not included in the eligibility criteria.',
            ];
        }

        $batchIds = $opportunity->eligibleBatches->pluck('batch_id');
        if ($batchIds->isNotEmpty()
            && (! $student->batch_id || ! $batchIds->contains($student->batch_id))) {
            $reasons[] = [
                'code' => 'BATCH',
                'message' => 'Your batch is not included in the eligibility criteria.',
            ];
        }

        $years = $opportunity->eligibleYears->pluck('year');
        if ($years->isNotEmpty()
            && ($student->year === null || ! $years->contains((int) $student->year))) {
            $reasons[] = [
                'code' => 'YEAR',
                'message' => 'Your academic year is not included in the eligibility criteria.',
            ];
        }

        // A missing CGPA/backlog count is never read as 0 - it fails a
        // gated criterion, with a reason saying it isn't on record yet.
        $minCgpa = $criteria?->min_cgpa;
        if ($minCgpa !== null && $student->cgpa === null) {
            $reasons[] = [
                'code' => 'CGPA',
                'message' => sprintf('Minimum CGPA required is %.2f, and your CGPA is not yet available from the college.', (float) $minCgpa),
            ];
        } elseif ($minCgpa !== null && (float) $student->cgpa < (float) $minCgpa) {
            $reasons[] = [
                'code' => 'CGPA',
                'message' => sprintf('Minimum CGPA required is %.2f.', (float) $minCgpa),
            ];
        }

        $maxBacklogs = $criteria?->max_backlogs;
        if ($maxBacklogs !== null && $student->backlogs === null) {
            $reasons[] = [
                'code' => 'BACKLOGS',
                'message' => sprintf('Maximum allowed backlogs is %d, and your backlogs have not been reported by the college.', (int) $maxBacklogs),
            ];
        } elseif ($maxBacklogs !== null && (int) $student->backlogs > (int) $maxBacklogs) {
            $reasons[] = [
                'code' => 'BACKLOGS',
                'message' => sprintf('Maximum allowed backlogs is %d.', (int) $maxBacklogs),
            ];
        }

        $requiredSkills = $opportunity->requiredSkills->pluck('skill_name');
        if ($requiredSkills->isNotEmpty()) {
            // ALL required skills must be present - no fuzzy matching,
            // just an exact (case-insensitive, whitespace-normalized)
            // comparison against the student's own listed skills.
            $studentSkills = $student->skills->pluck('skill_name')
                ->map(fn ($name) => $this->normalize($name))
                ->all();

            $missing = $requiredSkills->filter(
                fn ($skill) => ! in_array($this->normalize($skill), $studentSkills, true)
            )->values();

            if ($missing->isNotEmpty()) {
                $reasons[] = [
                    'code' => 'SKILLS',
                    'message' => 'You are missing required skills: '.$missing->implode(', ').'.',
                ];
            }
        }

        return $reasons;
    }

    /**
     * Same trim/collapse-whitespace/case-fold normalization
     * HasEligibilityCriteria::normalizeSkillNames() applies before
     * storing a required skill - kept as a small, self-contained
     * duplicate here rather than a cross-trait static call (traits
     * aren't callable via TraitName::method()) so both sides of the
     * comparison (stored required skill, student's own skill) are
     * folded identically without coupling this service to a model
     * trait it otherwise has no reason to depend on.
     */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
