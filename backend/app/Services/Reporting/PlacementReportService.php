<?php

namespace App\Services\Reporting;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Student;
use App\Services\Reporting\Concerns\AppliesReportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reports & Analytics module - placement-level analytics, with a
 * strict STUDENTS-vs-APPLICATIONS distinction maintained throughout
 * (a student with 5 applications counts once in every "students_*"
 * figure and 5 times in every "applications"/"total_applications"
 * figure - never conflated, mirroring
 * App\Services\ExcelExportService::buildApplicationSummary()'s own
 * headline rule).
 *
 * This is the single authoritative source for every placement metric
 * this codebase computes - App\Services\ExcelExportService's analytical
 * exports (Application Summary, Status Summary, Company/Department/
 * Batch-wise Placement Report) call into summary()/breakdownBy() here
 * rather than running their own copy of these aggregate queries, so
 * the API and the Excel Sync Center can never silently drift apart.
 *
 * SEMANTIC DEFINITIONS (deliberately explicit - see module spec
 * section 18's "document every non-obvious counting rule"):
 *
 *  - students_applied / unique_applicants: both name the SAME figure
 *    (distinct applicant count against the application-level filters)
 *    - kept as two keys only because both names appear in different
 *      parts of the spec; they are never allowed to diverge.
 *  - students_shortlisted / students_interviewed: HISTORICAL
 *    ("ever reached this stage"), not a current-status snapshot.
 *    students_shortlisted is computed from ApplicationStatusHistory
 *    (>=1 row with to_status = SHORTLISTED) rather than
 *    Application.status, because a student who was shortlisted and
 *    later progressed to ASSESSMENT/INTERVIEW/SELECTED - or was
 *    shortlisted and later REJECTED - no longer shows status =
 *    SHORTLISTED today, yet unquestionably WAS shortlisted. This is
 *    exactly the "current status vs historical transitions"
 *    distinction the module spec calls out: Application.status is a
 *    single current-value column, ApplicationStatusHistory is the
 *    append-only historical log, and "how many students were ever
 *    shortlisted this season" is a historical question, not a
 *    current-snapshot one. students_interviewed is computed from the
 *    actual Interview table (>=1 interview row for the application)
 *    for the identical reason, and because it is strictly more
 *    accurate than inferring "was interviewed" from
 *    Application.status = INTERVIEW (which stops being true the
 *    moment the application moves on to SELECTED/REJECTED). Contrast
 *    this with App\Services\Reporting\ApplicationReportService::funnel(),
 *    which is deliberately the OTHER kind of metric - a live,
 *    current-status snapshot distribution across all 8
 *    ApplicationStatus values - the two reports answer different
 *    questions and must not be reconciled into one number.
 *  - students_selected / applications_rejected / applications_withdrawn:
 *    SELECTED/REJECTED/WITHDRAWN are terminal statuses
 *    (ApplicationStatus::isTerminal()) - once reached, an application
 *    never leaves them, so the current-status column and "ever
 *    reached" always agree here; the simpler current-status query is
 *    used.
 *  - students_unplaced: every in-scope student with zero SELECTED
 *    application, ever - mirrors
 *    ExcelExportService::buildUnplacedStudentReport()'s
 *    whereDoesntHave('applications', status = SELECTED) exactly.
 *  - companies_count / job_opportunities_count: distinct companies /
 *    distinct opportunities (job postings + internships, counted
 *    together) that received at least one in-scope application - not
 *    a count of every company/opportunity that merely exists, since
 *    this is a report about placement ACTIVITY, not the opportunity
 *    catalog.
 *  - selection_rate: students_selected / unique_applicants * 100,
 *    i.e. "of the students who applied, what fraction were placed" -
 *    never divided by total_students (a student who never applied is
 *    not part of this rate's denominator).
 */
class PlacementReportService
{
    use AppliesReportFilters;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float>
     */
    public function summary(array $filters): array
    {
        $studentQuery = Student::query();
        $this->applyStudentOwnScope($studentQuery, $filters);
        $totalStudents = (clone $studentQuery)->count();

        $applicationQuery = Application::query();
        $this->applyStudentRelationScope($applicationQuery, $filters);
        $this->applyDateRange($applicationQuery, 'applied_at', $filters);
        $this->applyCompanyScope($applicationQuery, $filters);
        $this->applyApplicableType($applicationQuery, $filters);

        $totalApplications = (clone $applicationQuery)->count();
        $uniqueApplicants = (clone $applicationQuery)->distinct('student_id')->count('student_id');

        $studentsShortlisted = $this->countStudentsEverReaching(ApplicationStatus::SHORTLISTED, $filters);
        $studentsInterviewed = $this->countStudentsInterviewed($filters);

        $studentsSelected = (clone $applicationQuery)->where('status', ApplicationStatus::SELECTED->value)->distinct('student_id')->count('student_id');
        $applicationsRejected = (clone $applicationQuery)->where('status', ApplicationStatus::REJECTED->value)->count();
        $applicationsWithdrawn = (clone $applicationQuery)->where('status', ApplicationStatus::WITHDRAWN->value)->count();

        $unplacedQuery = Student::query()->whereDoesntHave('applications', fn (Builder $q) => $q->where('status', ApplicationStatus::SELECTED->value));
        $this->applyStudentOwnScope($unplacedQuery, $filters);
        $studentsUnplaced = $unplacedQuery->count();

        $opportunityPairs = (clone $applicationQuery)->select('applicable_type', 'applicable_id')->distinct()->get();
        $jobOpportunityIds = $opportunityPairs->where('applicable_type', JobPosting::class)->pluck('applicable_id')->unique()->values();
        $internshipOpportunityIds = $opportunityPairs->where('applicable_type', Internship::class)->pluck('applicable_id')->unique()->values();
        $jobOpportunitiesCount = $jobOpportunityIds->count() + $internshipOpportunityIds->count();

        $companiesCount = JobPosting::query()->whereIn('id', $jobOpportunityIds)->pluck('company_id')
            ->merge(Internship::query()->whereIn('id', $internshipOpportunityIds)->pluck('company_id'))
            ->unique()
            ->count();

        return [
            'total_students' => $totalStudents,
            'students_applied' => $uniqueApplicants,
            'students_not_applied' => max($totalStudents - $uniqueApplicants, 0),
            'total_applications' => $totalApplications,
            'unique_applicants' => $uniqueApplicants,
            'avg_applications_per_applicant' => $uniqueApplicants > 0 ? round($totalApplications / $uniqueApplicants, 2) : 0.0,
            'students_shortlisted' => $studentsShortlisted,
            'students_interviewed' => $studentsInterviewed,
            'students_selected' => $studentsSelected,
            'applications_rejected' => $applicationsRejected,
            'applications_withdrawn' => $applicationsWithdrawn,
            'students_unplaced' => $studentsUnplaced,
            'companies_count' => $companiesCount,
            'job_opportunities_count' => $jobOpportunitiesCount,
            'selection_rate' => $uniqueApplicants > 0 ? round(($studentsSelected / $uniqueApplicants) * 100, 2) : 0.0,
        ];
    }

    /**
     * The institution-level placement report broken down by one
     * dimension - company, department, or batch. This is the exact
     * aggregation ExcelExportService's buildCompanyWisePlacementReport()/
     * buildDepartmentWisePlacementReport()/buildBatchWisePlacementReport()
     * now delegate to (see that service's docblock), generalized only
     * by which column is grouped on - the join/aggregate SQL itself is
     * unchanged from the original implementation.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{id: int|string, label: string, total_applications: int, distinct_students: int, students_selected: int, selection_rate: float}>
     */
    public function breakdownBy(string $dimension, array $filters): array
    {
        [$groupByColumn, $groupByLabelColumn, $joinCompanies, $joinDepartments, $joinBatches] = match ($dimension) {
            'company' => ['companies.id', 'companies.name', true, false, false],
            'department' => ['departments.id', 'departments.name', false, true, false],
            'batch' => ['batches.id', 'batches.name', false, false, true],
            default => throw new \InvalidArgumentException("Unsupported placement breakdown dimension [{$dimension}]."),
        };

        $query = DB::table('applications')
            ->join('students', 'students.id', '=', 'applications.student_id');

        if ($joinCompanies) {
            $query->join('job_postings', function ($j) {
                $j->on('job_postings.id', '=', 'applications.applicable_id')->where('applications.applicable_type', '=', JobPosting::class);
            }, type: 'left')
                ->join('internships', function ($j) {
                    $j->on('internships.id', '=', 'applications.applicable_id')->where('applications.applicable_type', '=', Internship::class);
                }, type: 'left')
                ->join('companies', function ($j) {
                    $j->on('companies.id', '=', 'job_postings.company_id')->orOn('companies.id', '=', 'internships.company_id');
                }, type: 'left');
        }

        if ($joinDepartments) {
            $query->join('departments', 'departments.id', '=', 'students.department_id');
        }

        if ($joinBatches) {
            $query->join('batches', 'batches.id', '=', 'students.batch_id');
        }

        if (! empty($filters['department_id'])) {
            $query->where('students.department_id', $filters['department_id']);
        }

        if (! empty($filters['batch_id'])) {
            $query->where('students.batch_id', $filters['batch_id']);
        }

        if (! empty($filters['year'])) {
            $query->where('students.year', $filters['year']);
        }

        if (! empty($filters['section'])) {
            $query->where('students.section', self::normalizedSection($filters['section']));
        }

        if (! empty($filters['applicable_type'])) {
            $query->where('applications.applicable_type', $filters['applicable_type'] === 'internship' ? Internship::class : JobPosting::class);
        }

        if (! empty($filters['company_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where(function ($q2) use ($filters) {
                    $q2->where('applications.applicable_type', JobPosting::class)
                        ->whereIn('applications.applicable_id', JobPosting::query()->where('company_id', $filters['company_id'])->select('id'));
                })->orWhere(function ($q2) use ($filters) {
                    $q2->where('applications.applicable_type', Internship::class)
                        ->whereIn('applications.applicable_id', Internship::query()->where('company_id', $filters['company_id'])->select('id'));
                });
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('applications.applied_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('applications.applied_at', '<=', $filters['date_to']);
        }

        $rows = $query
            ->whereNotNull($groupByColumn)
            ->selectRaw(sprintf(
                '%s as group_id, %s as group_label, COUNT(*) as total_applications, COUNT(DISTINCT applications.student_id) as distinct_students, COUNT(DISTINCT CASE WHEN applications.status = ? THEN applications.student_id END) as students_selected',
                $groupByColumn,
                $groupByLabelColumn,
            ))
            ->addBinding(ApplicationStatus::SELECTED->value, 'select')
            ->groupBy($groupByColumn, $groupByLabelColumn)
            ->orderBy('group_label')
            ->get();

        return $rows->map(fn ($r) => [
            'id' => $r->group_id,
            'label' => $r->group_label,
            'total_applications' => (int) $r->total_applications,
            'distinct_students' => (int) $r->distinct_students,
            'students_selected' => (int) $r->students_selected,
            'selection_rate' => $r->distinct_students > 0 ? round(($r->students_selected / $r->distinct_students) * 100, 2) : 0.0,
        ])->all();
    }

    /**
     * One row per department + year + section: how many students the
     * class has, how many applied, how many were placed (>=1 SELECTED
     * application) and how many are still unplaced. Unlike breakdownBy(),
     * which only sees students who applied, this counts from the
     * students table, so a class where nobody applied still shows up
     * with its full headcount. Student filters (department/batch/year/
     * section) narrow the classes; application filters (dates, company,
     * job vs internship) narrow which applications count as "applied"
     * and "placed". With no application filters, students_unplaced
     * matches the Unplaced Student Report row for row.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{department: ?string, year: ?int, section: ?string, total_students: int, students_applied: int, students_placed: int, students_unplaced: int, placement_rate: float}>
     */
    public function classBreakdown(array $filters): array
    {
        $applied = Application::query();
        $this->applyDateRange($applied, 'applied_at', $filters);
        $this->applyCompanyScope($applied, $filters);
        $this->applyApplicableType($applied, $filters);

        $placed = (clone $applied)->where('status', ApplicationStatus::SELECTED->value)->select('student_id')->toBase();
        $applied = $applied->select('student_id')->toBase();

        $inScope = Student::query();
        $this->applyStudentOwnScope($inScope, $filters);

        $rows = DB::table('students')
            ->whereIn('students.id', $inScope->select('id'))
            ->leftJoin('departments', 'departments.id', '=', 'students.department_id')
            ->selectRaw(sprintf(
                'departments.name as department, students.year as year, students.section as section, COUNT(*) as total_students, '
                .'SUM(CASE WHEN students.id IN (%s) THEN 1 ELSE 0 END) as students_applied, '
                .'SUM(CASE WHEN students.id IN (%s) THEN 1 ELSE 0 END) as students_placed',
                $applied->toSql(),
                $placed->toSql(),
            ))
            ->addBinding([...$applied->getBindings(), ...$placed->getBindings()], 'select')
            ->groupBy('departments.name', 'students.year', 'students.section')
            ->orderBy('departments.name')
            ->orderBy('students.year')
            ->orderBy('students.section')
            ->get();

        return $rows->map(function ($r) {
            $total = (int) $r->total_students;
            $placedCount = (int) $r->students_placed;

            return [
                'department' => $r->department,
                'year' => $r->year === null ? null : (int) $r->year,
                'section' => $r->section,
                'total_students' => $total,
                'students_applied' => (int) $r->students_applied,
                'students_placed' => $placedCount,
                'students_unplaced' => $total - $placedCount,
                'placement_rate' => $total > 0 ? round(($placedCount / $total) * 100, 2) : 0.0,
            ];
        })->all();
    }

    /**
     * Distinct students whose applications ever transitioned to the
     * given status, per ApplicationStatusHistory - see this class's
     * docblock for why this is historical rather than current-status.
     *
     * @param  array<string, mixed>  $filters
     */
    private function countStudentsEverReaching(ApplicationStatus $status, array $filters): int
    {
        $applicationIds = ApplicationStatusHistory::query()
            ->where('to_status', $status->value)
            ->pluck('application_id')
            ->unique()
            ->values();

        $query = Application::query()->whereIn('id', $applicationIds);
        $this->applyStudentRelationScope($query, $filters);
        $this->applyDateRange($query, 'applied_at', $filters);
        $this->applyCompanyScope($query, $filters);
        $this->applyApplicableType($query, $filters);

        return (clone $query)->distinct('student_id')->count('student_id');
    }

    /**
     * Distinct students with at least one Interview row against any of
     * their applications - the actual Interview table is ground truth,
     * never Application.status = INTERVIEW (see this class's docblock).
     *
     * @param  array<string, mixed>  $filters
     */
    private function countStudentsInterviewed(array $filters): int
    {
        $applicationIds = DB::table('interviews')->pluck('application_id')->unique()->values();

        $query = Application::query()->whereIn('id', $applicationIds);
        $this->applyStudentRelationScope($query, $filters);
        $this->applyDateRange($query, 'applied_at', $filters);
        $this->applyCompanyScope($query, $filters);
        $this->applyApplicableType($query, $filters);

        return (clone $query)->distinct('student_id')->count('student_id');
    }
}
