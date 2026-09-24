<?php

namespace App\Services;

use App\Enums\OpportunityStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\Student;

/**
 * The "Excel -> ... -> Match against MySQL" stage of the module spec's
 * section 1 pipeline - resolves Student / Company+Opportunity /
 * Application / InternshipAssignment / Interview from the human-
 * readable text a company/placement-cell Excel file contains. NEVER
 * writes to MySQL and never creates a record when nothing matches -
 * see this class's own per-method docblocks and the module spec's
 * sections 4-6 ("do not create a new student/company/application
 * automatically").
 *
 * Every match method returns the SAME three-outcome shape
 * ('MATCHED'|'AMBIGUOUS'|'NOT_FOUND') so App\Services\ExcelValidationService
 * can handle all of them uniformly rather than special-casing each
 * entity type's own result shape.
 *
 * Company/opportunity name comparisons are done in PHP against a
 * request-scoped, per-instance memoized company/opportunity list (see
 * $companyCache/$opportunityCache) rather than a fresh query per Excel
 * row - a 250-row import would otherwise run 250+ near-identical
 * queries; this service is constructed once per import request (see
 * App\Services\ExcelImportService), so the cache's lifetime is exactly
 * one batch.
 */
class ExcelMatchingService
{
    /**
     * An opportunity must be genuinely live in the placement pipeline
     * to be a valid match target - DRAFT (never published) and
     * ARCHIVED (retired) are excluded, mirroring
     * App\Services\InterviewService::INVALID_OPPORTUNITY_STATUSES
     * exactly. A CLOSED opportunity is still valid: closing it to new
     * applicants doesn't invalidate the existing pipeline for students
     * who already applied while it was open (same reasoning as
     * InterviewService).
     */
    private const VALID_OPPORTUNITY_STATUSES = [OpportunityStatus::PUBLISHED, OpportunityStatus::CLOSED];

    /** @var array<int, array{id: int, normalized: string}>|null */
    private ?array $companyCache = null;

    /**
     * Roll number first (this codebase's `students.roll_number` is
     * DB-unique - see the students migration - so this branch can only
     * ever return 0 or 1 rows), college email second (`users.email` is
     * also DB-unique). Never matches on name alone - module spec
     * section 4: "NEVER use student name as the primary unique
     * identifier."
     *
     * @return array{outcome: string, student: ?Student, message: ?string}
     */
    public function matchStudent(?string $rollNumber, ?string $email): array
    {
        $rollNumber = $this->cleanScalar($rollNumber);
        $email = $this->cleanScalar($email);

        if ($rollNumber === null && $email === null) {
            return ['outcome' => 'NOT_FOUND', 'student' => null, 'message' => 'No roll number or college email supplied to match a student.'];
        }

        if ($rollNumber !== null) {
            $student = Student::query()->whereRaw('UPPER(roll_number) = ?', [mb_strtoupper($rollNumber)])->first();

            if ($student !== null) {
                return ['outcome' => 'MATCHED', 'student' => $student, 'message' => null];
            }
        }

        if ($email !== null) {
            $student = Student::query()->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]))->first();

            if ($student !== null) {
                return ['outcome' => 'MATCHED', 'student' => $student, 'message' => null];
            }
        }

        return [
            'outcome' => 'NOT_FOUND',
            'student' => null,
            'message' => $rollNumber !== null
                ? sprintf('No student found with roll number %s.', $rollNumber)
                : sprintf('No student found with college email %s.', $email),
        ];
    }

    /**
     * Roll number/college-email matching is exact and never produces a
     * WARNING on its own - the name-mismatch WARNING (module spec
     * section 4's "If roll number matches but name is significantly
     * different") is computed HERE, against the already-matched
     * student, rather than folded into matching itself, because it is
     * advisory (the match still stands) rather than a matching
     * criterion. "Significantly different" is judged with Laravel's
     * own similar_text() percentage rather than exact string equality
     * - a matched student's Excel-supplied name will routinely differ
     * in spacing/capitalization/middle-name presence from the database
     * name without being a genuine mismatch.
     */
    public function nameLooksMismatched(Student $student, ?string $excelName): bool
    {
        $excelName = $this->cleanScalar($excelName);

        if ($excelName === null) {
            return false;
        }

        $dbName = $student->user?->name;

        if ($dbName === null || $dbName === '') {
            return false;
        }

        similar_text(mb_strtolower($this->normalizeText($dbName)), mb_strtolower($this->normalizeText($excelName)), $percent);

        return $percent < 60.0;
    }

    /**
     * Company + Opportunity (JobPosting or Internship) matching -
     * module spec section 5. $restrictTo narrows the search to one
     * opportunity class (INTERNSHIP_STATUS/ATTENDANCE imports are
     * inherently internship-only; other import types search both). A
     * company is matched first by normalized-name equality (never
     * fuzzy/alias resolution - module spec: "Do NOT assume unrelated
     * company aliases are identical"); the opportunity is then matched
     * by normalized title WITHIN that one company, restricted to
     * VALID_OPPORTUNITY_STATUSES.
     *
     * @param class-string<JobPosting|Internship>|null $restrictTo
     * @return array{outcome: string, opportunity: JobPosting|Internship|null, message: ?string}
     */
    public function matchOpportunity(?string $companyName, ?string $roleName, ?string $restrictTo = null): array
    {
        $companyName = $this->cleanScalar($companyName);
        $roleName = $this->cleanScalar($roleName);

        if ($companyName === null) {
            return ['outcome' => 'NOT_FOUND', 'opportunity' => null, 'message' => 'No company supplied.'];
        }

        $normalizedCompany = $this->normalizeText($companyName);
        $companies = array_values(array_filter(
            $this->companies(),
            fn (array $c) => $c['normalized'] === $normalizedCompany,
        ));

        if ($companies === []) {
            return ['outcome' => 'NOT_FOUND', 'opportunity' => null, 'message' => sprintf('No company found matching "%s".', $companyName)];
        }

        if (count($companies) > 1) {
            return ['outcome' => 'AMBIGUOUS', 'opportunity' => null, 'message' => sprintf('Multiple companies match "%s".', $companyName)];
        }

        if ($roleName === null) {
            return ['outcome' => 'NOT_FOUND', 'opportunity' => null, 'message' => 'No job role supplied.'];
        }

        $companyId = $companies[0]['id'];
        $normalizedRole = $this->normalizeText($roleName);
        $candidates = [];

        if ($restrictTo === null || $restrictTo === JobPosting::class) {
            foreach (JobPosting::query()->where('company_id', $companyId)->whereIn('status', self::VALID_OPPORTUNITY_STATUSES)->get(['id', 'title']) as $posting) {
                if ($this->normalizeText($posting->title) === $normalizedRole) {
                    $candidates[] = $posting;
                }
            }
        }

        if ($restrictTo === null || $restrictTo === Internship::class) {
            foreach (Internship::query()->where('company_id', $companyId)->whereIn('status', self::VALID_OPPORTUNITY_STATUSES)->get(['id', 'title']) as $internship) {
                if ($this->normalizeText($internship->title) === $normalizedRole) {
                    $candidates[] = $internship;
                }
            }
        }

        if ($candidates === []) {
            return ['outcome' => 'NOT_FOUND', 'opportunity' => null, 'message' => sprintf('No published or closed opportunity found for "%s" at "%s".', $roleName, $companyName)];
        }

        if (count($candidates) > 1) {
            return ['outcome' => 'AMBIGUOUS', 'opportunity' => null, 'message' => sprintf('Multiple opportunities match "%s" at "%s".', $roleName, $companyName)];
        }

        return ['outcome' => 'MATCHED', 'opportunity' => $candidates[0], 'message' => null];
    }

    /**
     * Module spec section 6 - student_id + applicable_type + applicable_id,
     * the SAME uniqueness triple App\Services\ApplicationService::apply()
     * itself enforces (a DB-level unique constraint backs it), so
     * AMBIGUOUS is a defensive branch rather than one expected to ever
     * actually trigger.
     *
     * @return array{outcome: string, application: ?Application, message: ?string}
     */
    public function matchApplication(Student $student, JobPosting|Internship $opportunity): array
    {
        $applications = Application::query()
            ->where('student_id', $student->id)
            ->where('applicable_type', $opportunity::class)
            ->where('applicable_id', $opportunity->id)
            ->get();

        return match ($applications->count()) {
            0 => ['outcome' => 'NOT_FOUND', 'application' => null, 'message' => 'No existing application found for this student and opportunity.'],
            1 => ['outcome' => 'MATCHED', 'application' => $applications->first(), 'message' => null],
            default => ['outcome' => 'AMBIGUOUS', 'application' => null, 'message' => 'Multiple applications found for this student and opportunity.'],
        };
    }

    /**
     * Module spec section 15 - internship assignments are never
     * created from an Excel row (only matched); see
     * App\Services\ExcelImportService for the internship-status
     * importer that calls this (already holding a matched Internship
     * from matchOpportunity()).
     *
     * @return array{outcome: string, assignment: ?InternshipAssignment, message: ?string}
     */
    public function matchInternshipAssignment(Student $student, Internship $internship): array
    {
        $assignments = InternshipAssignment::query()
            ->where('student_id', $student->id)
            ->where('internship_id', $internship->id)
            ->get();

        return match ($assignments->count()) {
            0 => ['outcome' => 'NOT_FOUND', 'assignment' => null, 'message' => 'No internship assignment found for this student and internship.'],
            1 => ['outcome' => 'MATCHED', 'assignment' => $assignments->first(), 'message' => null],
            default => ['outcome' => 'AMBIGUOUS', 'assignment' => null, 'message' => 'Multiple internship assignments found for this student and internship.'],
        };
    }

    /**
     * The Attendance import's "Internship" column (module spec section
     * 16) is a SINGLE free-text cell that may hold either the
     * internship's own title or its company's name (its own header
     * aliases to "Company" for exactly this reason) - it is never a
     * company-then-role two-step lookup the way matchOpportunity() is
     * for imports that have separate Company/Job Role columns. Rather
     * than guessing which the text means, this searches the STUDENT'S
     * OWN internship assignments (never the whole opportunities table)
     * and matches the hint against either field - a strictly smaller,
     * safer search space, and the right one: attendance is always for
     * one specific assignment this student already holds. When the
     * student has exactly one assignment, the hint is optional entirely.
     *
     * @return array{outcome: string, assignment: ?InternshipAssignment, message: ?string}
     */
    public function matchStudentInternshipAssignment(Student $student, ?string $hint): array
    {
        $assignments = InternshipAssignment::query()
            ->with('internship.company')
            ->where('student_id', $student->id)
            ->get();

        if ($assignments->isEmpty()) {
            return ['outcome' => 'NOT_FOUND', 'assignment' => null, 'message' => 'This student has no internship assignment.'];
        }

        $hint = $this->cleanScalar($hint);

        if ($hint === null) {
            return $assignments->count() === 1
                ? ['outcome' => 'MATCHED', 'assignment' => $assignments->first(), 'message' => null]
                : ['outcome' => 'AMBIGUOUS', 'assignment' => null, 'message' => 'This student has more than one internship assignment; supply the Internship column to disambiguate.'];
        }

        $normalizedHint = $this->normalizeText($hint);
        $matches = $assignments->filter(fn (InternshipAssignment $a) => $this->normalizeText($a->internship?->title ?? '') === $normalizedHint
            || $this->normalizeText($a->internship?->company?->name ?? '') === $normalizedHint);

        return match ($matches->count()) {
            0 => ['outcome' => 'NOT_FOUND', 'assignment' => null, 'message' => sprintf('No internship assignment found matching "%s" for this student.', $hint)],
            1 => ['outcome' => 'MATCHED', 'assignment' => $matches->first(), 'message' => null],
            default => ['outcome' => 'AMBIGUOUS', 'assignment' => null, 'message' => sprintf('Multiple internship assignments match "%s" for this student.', $hint)],
        };
    }

    /**
     * The most recent NON-TERMINAL interview round for this
     * application, if one exists - used by the interview-schedule
     * importer to decide "create a new round" (none found) vs.
     * "act on this existing round" (found). Deliberately does not
     * consider terminal (COMPLETED/CANCELLED) rounds a match target -
     * those are closed history, never re-opened by an Excel row (same
     * "finalized/terminal records are immutable" principle as every
     * other module this session).
     */
    public function findActiveInterview(Application $application): ?Interview
    {
        return Interview::query()
            ->where('application_id', $application->id)
            ->whereIn('status', ['SCHEDULED', 'RESCHEDULED'])
            ->orderByDesc('scheduled_at')
            ->first();
    }

    /**
     * @return array<int, array{id: int, normalized: string}>
     */
    private function companies(): array
    {
        if ($this->companyCache === null) {
            $this->companyCache = Company::query()->get(['id', 'name'])
                ->map(fn (Company $c) => ['id' => $c->id, 'normalized' => $this->normalizeText($c->name)])
                ->all();
        }

        return $this->companyCache;
    }

    private function cleanScalar(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Module spec section 5 - "Normalize: case, whitespace, common
     * punctuation" - collapses internal whitespace, lowercases, and
     * strips a small, fixed set of punctuation characters that
     * routinely differ between an Excel cell and the database value
     * without being a genuinely different name (periods, commas,
     * parentheses, hyphens, ampersands, quotes). Deliberately NOT a
     * fuzzy/similarity match - see this class's own docblock.
     */
    private function normalizeText(string $value): string
    {
        $withoutPunctuation = preg_replace('/[.,()&\'"\-]/', ' ', $value) ?? $value;
        $collapsed = preg_replace('/\s+/', ' ', trim($withoutPunctuation)) ?? '';

        return mb_strtolower($collapsed);
    }
}
