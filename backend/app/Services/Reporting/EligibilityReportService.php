<?php

namespace App\Services\Reporting;

use App\Models\Application;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\PlacementDrive;
use App\Models\Student;
use App\Services\OpportunityEligibilityService;
use App\Services\Reporting\Concerns\AppliesReportFilters;

/**
 * Who is eligible for a job, internship or placement drive, and which of
 * them have applied. Eligibility is checked against the criteria as the
 * admin configured them (OpportunityEligibilityService::criteriaReasons()),
 * whether or not enforcement is switched on, so the placement office can
 * chase eligible students before the deadline.
 *
 * Each opportunity's rows are every eligible student in scope plus every
 * student who applied without meeting the criteria (possible when
 * enforcement is off), so "Eligible, applied" + "Eligible, not applied"
 * always equals "Eligible".
 */
class EligibilityReportService
{
    use AppliesReportFilters;

    private const CHUNK_SIZE = 500;

    public function __construct(private readonly OpportunityEligibilityService $eligibility) {}

    /**
     * The opportunities a job_id / internship_id / drive_id filter names.
     *
     * @param  array<string, mixed>  $filters
     * @return list<JobPosting|Internship>
     */
    public function opportunities(array $filters): array
    {
        $relations = ['company', 'eligibilityCriteria', 'eligibleDepartments', 'eligibleBatches', 'eligibleYears', 'requiredSkills'];

        if (! empty($filters['job_id'])) {
            return [JobPosting::with($relations)->findOrFail($filters['job_id'])];
        }

        if (! empty($filters['internship_id'])) {
            return [Internship::with($relations)->findOrFail($filters['internship_id'])];
        }

        if (! empty($filters['drive_id'])) {
            return PlacementDrive::findOrFail($filters['drive_id'])->opportunities()->with('opportunity')->get()
                ->pluck('opportunity')
                ->filter()
                ->each(fn ($o) => $o->load($relations))
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array{in_scope: int, eligible: int, eligible_applied: int, eligible_not_applied: int, applied_not_eligible: int}, rows: list<array{student: Student, eligible: bool, reasons: list<string>, application_status: ?string}>}
     */
    public function forOpportunity(JobPosting|Internship $opportunity, array $filters): array
    {
        $applications = Application::query()
            ->where('applicable_type', $opportunity::class)
            ->where('applicable_id', $opportunity->id)
            ->get(['student_id', 'status'])
            ->keyBy('student_id');

        $students = Student::query()->with(['user', 'department', 'batch', 'skills']);
        $this->applyStudentOwnScope($students, $filters);

        $rows = [];
        $summary = ['in_scope' => 0, 'eligible' => 0, 'eligible_applied' => 0, 'eligible_not_applied' => 0, 'applied_not_eligible' => 0];

        $students->chunkById(self::CHUNK_SIZE, function ($chunk) use ($opportunity, $applications, &$rows, &$summary) {
            foreach ($chunk as $student) {
                $summary['in_scope']++;
                $reasons = $this->eligibility->reportingReasons($student, $opportunity);
                $eligible = $reasons === [];
                $application = $applications->get($student->id);

                if ($eligible) {
                    $summary['eligible']++;
                    $summary[$application ? 'eligible_applied' : 'eligible_not_applied']++;
                } elseif ($application) {
                    $summary['applied_not_eligible']++;
                } else {
                    continue;
                }

                $rows[] = [
                    'student' => $student,
                    'eligible' => $eligible,
                    'reasons' => array_map(fn (array $r) => $this->shortReason($r, $student), $reasons),
                    'application_status' => $application ? ($application->status instanceof \BackedEnum ? $application->status->value : $application->status) : null,
                ];
            }
        });

        return ['summary' => $summary, 'rows' => $rows];
    }

    /** The configured criteria in one line, for the summary sheet. */
    public function criteriaText(JobPosting|Internship $opportunity): string
    {
        $criteria = $opportunity->eligibilityCriteria;
        $parts = [];

        $departments = $opportunity->eligibleDepartments->pluck('department_id');
        if ($departments->isNotEmpty()) {
            $parts[] = 'Departments: '.Department::whereIn('id', $departments)->orderBy('name')->pluck('code')->implode(', ');
        }

        $batches = $opportunity->eligibleBatches->pluck('batch_id');
        if ($batches->isNotEmpty()) {
            $parts[] = 'Batches: '.Batch::whereIn('id', $batches)->orderBy('name')->pluck('name')->implode(', ');
        }

        $years = $opportunity->eligibleYears->pluck('year')->sort();
        if ($years->isNotEmpty()) {
            $parts[] = 'Years: '.$years->implode(', ');
        }

        if ($criteria?->min_cgpa !== null) {
            $parts[] = sprintf('CGPA >= %.2f', (float) $criteria->min_cgpa);
        }

        if ($criteria?->max_backlogs !== null) {
            $parts[] = sprintf('Backlogs <= %d', (int) $criteria->max_backlogs);
        }

        $skills = $opportunity->requiredSkills->pluck('skill_name');
        if ($skills->isNotEmpty()) {
            $parts[] = 'Skills: '.$skills->implode(', ');
        }

        if ($parts === []) {
            return 'No criteria set (every student is eligible)';
        }

        return implode('; ', $parts).(($criteria?->eligibility_enforced ?? false) ? ' (enforced)' : ' (not enforced)');
    }

    /**
     * @param  array{code: string, message: string}  $reason
     */
    private function shortReason(array $reason, Student $student): string
    {
        return match ($reason['code']) {
            'DEPARTMENT' => 'Department',
            'BATCH' => 'Batch',
            'YEAR' => 'Year',
            'CGPA' => $student->cgpa === null ? 'CGPA not available' : 'CGPA too low',
            'BACKLOGS' => $student->backlogs === null ? 'Backlogs not reported' : 'Too many backlogs',
            'SKILLS' => 'Missing skills: '.rtrim(substr($reason['message'], strlen('You are missing required skills: ')), '.'),
            default => $reason['code'],
        };
    }
}
