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

        if (! empty($filters['company_id']) && $joinCompanies) {
            $query->where('companies.id', $filters['company_id']);
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

        return (clone $query)->distinct('student_id')->count('student_id');
    }

    /**
     * company_id only ever narrows an Application-based query (an
     * application's opportunity belongs to a company via
     * applicable_type/applicable_id) - it has no meaning on its own
     * for a Student-based query. Resolved via two plain subqueries
     * (job postings and internships belonging to the company) rather
     * than a raw join, so this stays composable with whatever other
     * filters/joins the caller has already applied.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyCompanyScope(Builder $query, array $filters): void
    {
        if (empty($filters['company_id'])) {
            return;
        }

        $companyId = $filters['company_id'];

        $query->where(function (Builder $q) use ($companyId) {
            $q->where(function (Builder $q2) use ($companyId) {
                $q2->where('applicable_type', JobPosting::class)
                    ->whereIn('applicable_id', JobPosting::query()->where('company_id', $companyId)->pluck('id'));
            })->orWhere(function (Builder $q2) use ($companyId) {
                $q2->where('applicable_type', Internship::class)
                    ->whereIn('applicable_id', Internship::query()->where('company_id', $companyId)->pluck('id'));
            });
        });
    }
}
