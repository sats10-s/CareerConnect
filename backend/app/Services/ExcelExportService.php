<?php

namespace App\Services;

use App\Enums\ExcelExportType;
use App\Exceptions\ExcelImportException;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\InternshipCompletion;
use App\Models\InternshipDocument;
use App\Models\Interview;
use App\Models\PlacementDrive;
use App\Models\PpoRecord;
use App\Models\Student;
use App\Models\WeeklyProgressReport;
use App\Services\Reporting\ApplicationReportService;
use App\Services\Reporting\Concerns\AppliesReportFilters;
use App\Services\Reporting\EligibilityReportService;
use App\Services\Reporting\PackageReportService;
use App\Services\Reporting\PlacementReportService;
use App\Services\Support\SheetTitleSanitizer;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds every downloadable Excel Sync Center export (module spec
 * sections 19-31) as an in-memory PhpSpreadsheet workbook - the
 * controller (Api\Admin\ExcelExportController) is the only thing that
 * ever turns the returned Spreadsheet into bytes on the wire (via
 * PhpOffice\PhpSpreadsheet\Writer\Xlsx), so this class has zero HTTP
 * concerns and is trivially unit-testable.
 *
 * RAW exports (module spec section 19) stream straight off the
 * relevant Eloquent model with eager-loaded relations and
 * chunkById() - never a bare ->get() - so a 10,000-row Students export
 * does not hold 10,000 full models (with relations) in memory at once,
 * and never runs an N+1 query per row for its "Department"/"Batch"
 * columns.
 *
 * ANALYTICAL exports (module spec sections 20-31) are computed with a
 * single GROUP BY/COUNT(DISTINCT ...) query per metric rather than by
 * pulling every row into PHP and tallying it here - the module spec's
 * own "STUDENTS vs APPLICATIONS" distinction (one student may have
 * several applications; a naive COUNT(*) conflates the two) is the
 * textbook reason to let MySQL do this with COUNT(DISTINCT student_id)
 * alongside a plain COUNT(*), not to loop over a collection in PHP.
 *
 * Every raw/analytical builder accepts the SAME `$filters` shape
 * (module spec sections 19/26 - "filterable by ..."):
 *   department_id, batch_id, year, section, company_id, status,
 *   faculty_id, applicable_type ('job'|'internship'), date_from, date_to
 * The student filters (department/batch/year/section) are applied
 * through App\Services\Reporting\Concerns\AppliesReportFilters, the
 * same helpers the /api/admin/reports/* endpoints use, and every export
 * that lists students carries the same four Department/Batch/Year/
 * Section columns (studentColumns()).
 * A filter that does not apply to a given export type is silently
 * ignored by that type's own applyFilters() (documented per method) -
 * this mirrors how every list endpoint in this codebase already
 * treats an inapplicable query parameter, rather than erroring.
 */
class ExcelExportService
{
    use AppliesReportFilters;

    private const CHUNK_SIZE = 500;

    /** The college placement of a student, in every export that lists students. */
    private const STUDENT_HEADERS = ['Department', 'Batch', 'Year', 'Section'];

    /**
     * The `student_status` filter's values, each paired with its
     * opposite. "shortlisted"/"interviewed" mean EVER reached that
     * stage (status history / Interview rows), the same definition
     * PlacementReportService uses, so a student who was shortlisted and
     * later selected still counts as shortlisted.
     */
    public const STUDENT_STATUSES = ['applied', 'not_applied', 'shortlisted', 'not_shortlisted', 'interviewed', 'not_interviewed', 'placed', 'unplaced'];

    private const HEADER_FILL = 'FFE2E8F0';

    /**
     * Reports & Analytics module - the Application Summary/Status
     * Summary/X-wise Placement Report analytical exports below now
     * delegate their aggregation to these two Reporting services
     * (App\Services\Reporting\PlacementReportService::summary()/
     * breakdownBy(), App\Services\Reporting\ApplicationReportService::funnel())
     * rather than running their own copy of the same GROUP BY/COUNT
     * queries. This is deliberate, not incidental: it is what makes the
     * Excel export and the `/api/admin/reports/*` endpoints
     * mathematically incapable of disagreeing - both read the exact
     * same query. See PlacementReportService's own docblock for the
     * full semantic definitions (students_shortlisted/interviewed as
     * historical figures, selection_rate's denominator, etc.) that
     * therefore now also govern these Excel exports, superseding this
     * class's own prior from-scratch computation of the same numbers.
     */
    public function __construct(
        private readonly PlacementReportService $placementReportService,
        private readonly ApplicationReportService $applicationReportService,
        private readonly EligibilityReportService $eligibilityReportService,
        private readonly PackageReportService $packageReportService,
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function build(ExcelExportType $type, array $filters): Spreadsheet
    {
        return $type->isRaw()
            ? $this->buildRaw($type, $filters)
            : $this->buildAnalytical($type, $filters);
    }

    // =========================================================================
    // RAW EXPORTS
    // =========================================================================

    /**
     * @param array<string, mixed> $filters
     */
    private function buildRaw(ExcelExportType $type, array $filters): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize($type->label()));

        match ($type) {
            ExcelExportType::STUDENTS => $this->writeStudents($sheet, $filters),
            ExcelExportType::APPLICATIONS => $this->writeApplications($sheet, $filters),
            ExcelExportType::INTERVIEWS => $this->writeInterviews($sheet, $filters),
            ExcelExportType::INTERNSHIP_ASSIGNMENTS => $this->writeInternshipAssignments($sheet, $filters),
            ExcelExportType::PPO_RECORDS => $this->writePpoRecords($sheet, $filters),
            ExcelExportType::ATTENDANCE => $this->writeAttendance($sheet, $filters),
            ExcelExportType::INTERNSHIP_COMPLETIONS => $this->writeInternshipCompletions($sheet, $filters),
            ExcelExportType::WEEKLY_PROGRESS_REPORTS => $this->writeWeeklyProgressReports($sheet, $filters),
            ExcelExportType::INTERNSHIP_DOCUMENTS => $this->writeInternshipDocuments($sheet, $filters),
            ExcelExportType::PLACEMENT_DRIVES => $this->writePlacementDrives($sheet, $filters),
            ExcelExportType::COMPANIES => $this->writeCompanies($sheet, $filters),
            default => throw new ExcelImportException('Not a raw export type.'),
        };

        return $spreadsheet;
    }

    /**
     * Supports: department_id, batch_id, year, section, student_status,
     * cgpa_min, cgpa_max, max_backlogs, backlogs, entry_type,
     * year_of_joining (see AppliesReportFilters).
     *
     * A blank CGPA (the 2024-28 batch has none yet) or blank backlogs
     * cell is written as text, never 0, so it cannot be read as a real
     * score or as "no backlogs".
     *
     * @param array<string, mixed> $filters
     */
    private function writeStudents(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Name', 'College Email', 'Personal Email', ...self::STUDENT_HEADERS, 'Gender', 'Program', 'Entry Type', 'Year of Joining', 'Phone', 'CGPA', 'Backlogs', '10th Score', '10th Scale', 'Inter/Diploma Score', 'Inter/Diploma', 'Address', 'Faculty Mentor', 'LinkedIn', 'GitHub', 'Portfolio'];
        $this->writeHeader($sheet, $headers);

        $query = Student::query()->with(['user', 'department', 'batch', 'facultyMentor.user']);
        $this->applyStudentOwnScope($query, $filters);
        $this->applyStudentOutcome($query, $filters);

        $this->streamRows($sheet, $query, fn (Student $s) => [
            $s->roll_number,
            $s->user?->name,
            $s->user?->email,
            $s->personal_email,
            ...$this->studentColumns($s),
            $s->gender ? ucfirst($s->gender->value) : null,
            $s->program ? match ($s->program->value) { 'btech' => 'B.Tech', 'mtech' => 'M.Tech', default => $s->program->value } : null,
            $s->entry_type ? ucfirst($s->entry_type->value) : null,
            $s->year_of_joining,
            $s->phone,
            $s->cgpa === null ? 'Not yet available' : (float) $s->cgpa,
            $s->backlogs ?? 'Not reported',
            $s->ssc_score === null ? null : (float) $s->ssc_score,
            $s->ssc_scale ? ($s->ssc_scale->value === 'percent' ? '%' : 'CGPA (10)') : null,
            $s->inter_score === null ? null : (float) $s->inter_score,
            $s->inter_type ? ucfirst($s->inter_type->value) : null,
            $s->address,
            $s->facultyMentor?->user?->name,
            $s->linkedin_url,
            $s->github_url,
            $s->portfolio_url,
        ]);
    }

    /**
     * Supports: status, date_from/date_to (on applied_at), department_id,
     * batch_id, year, section, applicable_type ('job'|'internship'),
     * company_id.
     *
     * @param array<string, mixed> $filters
     */
    private function writeApplications(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Opportunity Type', 'Company', 'Role/Title', 'Status', 'Applied At', 'Selected At', 'Withdrawn At', 'Rejection Reason'];
        $this->writeHeader($sheet, $headers);

        $query = Application::query()->with(['student.user', 'student.department', 'student.batch', 'applicable.company']);
        $this->applyDateRange($query, 'applied_at', $filters);
        $this->applyStudentRelationScope($query, $filters);
        $this->applyApplicableType($query, $filters);
        $this->applyCompanyScope($query, $filters);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->streamRows($sheet, $query, function (Application $a) {
            $opportunity = $a->applicable;

            return [
                $a->student?->roll_number,
                $a->student?->user?->name,
                ...$this->studentColumns($a->student),
                $a->applicable_type === Internship::class ? 'Internship' : 'Job',
                $opportunity?->company?->name,
                $opportunity?->title,
                $a->status->value,
                $a->applied_at?->toDateTimeString(),
                $a->selected_at?->toDateTimeString(),
                $a->withdrawn_at?->toDateTimeString(),
                $a->rejection_reason,
            ];
        });
    }

    /**
     * Supports: status, date_from/date_to (on scheduled_at),
     * department_id, batch_id, year, section.
     *
     * @param array<string, mixed> $filters
     */
    private function writeInterviews(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Company', 'Role/Title', 'Round', 'Status', 'Scheduled At', 'Mode', 'Venue', 'Meeting Link', 'Interviewer'];
        $this->writeHeader($sheet, $headers);

        $query = Interview::query()->with(['application.student.user', 'application.student.department', 'application.student.batch', 'application.applicable.company']);
        $this->applyDateRange($query, 'scheduled_at', $filters);
        $this->applyStudentRelationScope($query, $filters, 'application.student');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->streamRows($sheet, $query, function (Interview $i) {
            $application = $i->application;
            $opportunity = $application?->applicable;

            return [
                $application?->student?->roll_number,
                $application?->student?->user?->name,
                ...$this->studentColumns($application?->student),
                $opportunity?->company?->name,
                $opportunity?->title,
                $i->round,
                $i->status->value,
                $i->scheduled_at?->toDateTimeString(),
                $i->mode,
                $i->venue,
                $i->meeting_url,
                $i->interviewer_name,
            ];
        });
    }

    /**
     * Supports: status, department_id, batch_id, year, section,
     * company_id, faculty_id, date_from/date_to (on assigned_at).
     *
     * @param array<string, mixed> $filters
     */
    private function writeInternshipAssignments(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Company', 'Internship Role', 'Faculty', 'Status', 'Assigned At', 'Started At', 'Ended At'];
        $this->writeHeader($sheet, $headers);

        $query = InternshipAssignment::query()->with(['student.user', 'student.department', 'student.batch', 'internship.company', 'faculty.user']);
        $this->applyDateRange($query, 'assigned_at', $filters);
        $this->applyStudentRelationScope($query, $filters);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['faculty_id'])) {
            $query->where('faculty_id', $filters['faculty_id']);
        }

        if (! empty($filters['company_id'])) {
            $query->whereHas('internship', fn (Builder $q) => $q->where('company_id', $filters['company_id']));
        }

        $this->streamRows($sheet, $query, fn (InternshipAssignment $a) => [
            $a->student?->roll_number,
            $a->student?->user?->name,
            ...$this->studentColumns($a->student),
            $a->internship?->company?->name,
            $a->internship?->title,
            $a->faculty?->user?->name,
            $a->status->value,
            $a->assigned_at?->toDateTimeString(),
            $a->started_at?->toDateTimeString(),
            $a->ended_at?->toDateTimeString(),
        ]);
    }

    /**
     * Supports: status, company_id, date_from/date_to (on offered_at),
     * department_id, batch_id, year, section.
     *
     * @param array<string, mixed> $filters
     */
    private function writePpoRecords(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Company', 'Designation', 'Package', 'Status', 'Offered At', 'Joining Date'];
        $this->writeHeader($sheet, $headers);

        $query = PpoRecord::query()->with(['internshipAssignment.student.user', 'internshipAssignment.student.department', 'internshipAssignment.student.batch', 'company']);
        $this->applyDateRange($query, 'offered_at', $filters);
        $this->applyStudentRelationScope($query, $filters, 'internshipAssignment.student');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        $this->streamRows($sheet, $query, fn (PpoRecord $p) => [
            $p->internshipAssignment?->student?->roll_number,
            $p->internshipAssignment?->student?->user?->name,
            ...$this->studentColumns($p->internshipAssignment?->student),
            $p->company?->name,
            $p->designation,
            $p->package,
            $p->status->value,
            $p->offered_at?->toDateString(),
            $p->joining_date?->toDateString(),
        ]);
    }

    /**
     * Supports: status, date_from/date_to (on attendance_date),
     * department_id, batch_id, year, section.
     *
     * @param array<string, mixed> $filters
     */
    private function writeAttendance(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Internship', 'Date', 'Status', 'Check In', 'Check Out'];
        $this->writeHeader($sheet, $headers);

        $query = Attendance::query()->with(['internshipAssignment.student.user', 'internshipAssignment.student.department', 'internshipAssignment.student.batch', 'internshipAssignment.internship']);
        $this->applyDateRange($query, 'attendance_date', $filters);
        $this->applyStudentRelationScope($query, $filters, 'internshipAssignment.student');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->streamRows($sheet, $query, fn (Attendance $a) => [
            $a->internshipAssignment?->student?->roll_number,
            $a->internshipAssignment?->student?->user?->name,
            ...$this->studentColumns($a->internshipAssignment?->student),
            $a->internshipAssignment?->internship?->title,
            $a->attendance_date?->toDateString(),
            $a->status->value,
            $a->check_in_time,
            $a->check_out_time,
        ]);
    }

    /**
     * Supports: status, date_from/date_to (on completion_date),
     * department_id, batch_id, year, section.
     *
     * @param array<string, mixed> $filters
     */
    private function writeInternshipCompletions(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Company', 'Completion Date', 'Outcome', 'Status', 'Finalized At', 'Final Remarks'];
        $this->writeHeader($sheet, $headers);

        $query = InternshipCompletion::query()->with(['internshipAssignment.student.user', 'internshipAssignment.student.department', 'internshipAssignment.student.batch', 'internshipAssignment.internship.company']);
        $this->applyDateRange($query, 'completion_date', $filters);
        $this->applyStudentRelationScope($query, $filters, 'internshipAssignment.student');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->streamRows($sheet, $query, fn (InternshipCompletion $c) => [
            $c->internshipAssignment?->student?->roll_number,
            $c->internshipAssignment?->student?->user?->name,
            ...$this->studentColumns($c->internshipAssignment?->student),
            $c->internshipAssignment?->internship?->company?->name,
            $c->completion_date?->toDateString(),
            $c->outcome?->value,
            $c->status->value,
            $c->finalized_at?->toDateTimeString(),
            $c->final_remarks,
        ]);
    }

    /**
     * Supports: status, date_from/date_to (on week_start_date),
     * department_id, batch_id, year, section.
     *
     * @param array<string, mixed> $filters
     */
    private function writeWeeklyProgressReports(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Week #', 'Week Start', 'Week End', 'Status', 'Submitted At', 'Reviewed At'];
        $this->writeHeader($sheet, $headers);

        $query = WeeklyProgressReport::query()->with(['internshipAssignment.student.user', 'internshipAssignment.student.department', 'internshipAssignment.student.batch']);
        $this->applyDateRange($query, 'week_start_date', $filters);
        $this->applyStudentRelationScope($query, $filters, 'internshipAssignment.student');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->streamRows($sheet, $query, fn (WeeklyProgressReport $w) => [
            $w->internshipAssignment?->student?->roll_number,
            $w->internshipAssignment?->student?->user?->name,
            ...$this->studentColumns($w->internshipAssignment?->student),
            $w->week_number,
            $w->week_start_date?->toDateString(),
            $w->week_end_date?->toDateString(),
            $w->status->value,
            $w->student_submitted_at?->toDateTimeString(),
            $w->faculty_reviewed_at?->toDateTimeString(),
        ]);
    }

    /**
     * Supports: status, date_from/date_to (on created_at),
     * department_id, batch_id, year, section.
     *
     * @param array<string, mixed> $filters
     */
    private function writeInternshipDocuments(Worksheet $sheet, array $filters): void
    {
        $headers = ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Document Type', 'Title', 'Status', 'Submitted At', 'Reviewed At'];
        $this->writeHeader($sheet, $headers);

        $query = InternshipDocument::query()->with(['internshipAssignment.student.user', 'internshipAssignment.student.department', 'internshipAssignment.student.batch']);
        $this->applyDateRange($query, 'created_at', $filters);
        $this->applyStudentRelationScope($query, $filters, 'internshipAssignment.student');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->streamRows($sheet, $query, fn (InternshipDocument $d) => [
            $d->internshipAssignment?->student?->roll_number,
            $d->internshipAssignment?->student?->user?->name,
            ...$this->studentColumns($d->internshipAssignment?->student),
            $d->document_type?->value,
            $d->title,
            $d->status->value,
            $d->submitted_at?->toDateTimeString(),
            $d->reviewed_at?->toDateTimeString(),
        ]);
    }

    /**
     * Supports: status, company_id, date_from/date_to (on scheduled_at).
     *
     * @param array<string, mixed> $filters
     */
    private function writePlacementDrives(Worksheet $sheet, array $filters): void
    {
        $headers = ['Title', 'Company', 'Drive Type', 'Venue', 'Scheduled At', 'Application Deadline', 'Status'];
        $this->writeHeader($sheet, $headers);

        $query = PlacementDrive::query()->with(['company']);
        $this->applyDateRange($query, 'scheduled_at', $filters);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        $this->streamRows($sheet, $query, fn (PlacementDrive $d) => [
            $d->title,
            $d->company?->name,
            $d->drive_type?->value,
            $d->venue,
            $d->scheduled_at?->toDateTimeString(),
            $d->application_deadline?->toDateTimeString(),
            $d->status->value,
        ]);
    }

    /**
     * No filters supported beyond the implicit full listing - a
     * company directory is small enough that filtering it was never
     * requested by the module spec.
     *
     * @param array<string, mixed> $filters
     */
    private function writeCompanies(Worksheet $sheet, array $filters): void
    {
        $headers = ['Name', 'Industry', 'Website', 'Active'];
        $this->writeHeader($sheet, $headers);

        $this->streamRows($sheet, Company::query(), fn (Company $c) => [
            $c->name,
            $c->industry,
            $c->website,
            $c->is_active ? 'Yes' : 'No',
        ]);
    }

    // =========================================================================
    // ANALYTICAL / SUMMARY EXPORTS
    // =========================================================================

    /**
     * @param array<string, mixed> $filters
     */
    private function buildAnalytical(ExcelExportType $type, array $filters): Spreadsheet
    {
        return match ($type) {
            ExcelExportType::APPLICATION_SUMMARY => $this->buildApplicationSummary($filters),
            ExcelExportType::STATUS_SUMMARY => $this->buildStatusSummary($filters),
            ExcelExportType::COMPANY_WISE_PLACEMENT_REPORT => $this->buildCompanyWisePlacementReport($filters),
            ExcelExportType::DEPARTMENT_WISE_PLACEMENT_REPORT => $this->buildDepartmentWisePlacementReport($filters),
            ExcelExportType::BATCH_WISE_PLACEMENT_REPORT => $this->buildBatchWisePlacementReport($filters),
            ExcelExportType::SECTION_WISE_PLACEMENT_REPORT => $this->buildSectionWisePlacementReport($filters),
            ExcelExportType::ELIGIBLE_STUDENTS => $this->buildEligibleStudents($filters),
            ExcelExportType::PACKAGE_REPORT => $this->buildPackageReport($filters),
            ExcelExportType::BATCH_COMPARISON => $this->buildBatchComparison($filters),
            ExcelExportType::STUDENT_APPLICATION_REPORT => $this->buildStudentApplicationReport($filters),
            ExcelExportType::UNPLACED_STUDENT_REPORT => $this->buildUnplacedStudentReport($filters),
            ExcelExportType::STUDENT_APPLICATION_ACTIVITY => $this->buildStudentApplicationActivity($filters),
            default => throw new ExcelImportException('Not an analytical export type.'),
        };
    }

    /**
     * Reports & Analytics module - this sheet's headline figures now
     * come from App\Services\Reporting\PlacementReportService::summary()
     * (see this class's constructor docblock) rather than being
     * recomputed here; only the "by opportunity type" breakdown sheet
     * below is still queried directly, since it is not one of
     * PlacementReportService's own published metrics. Module spec's
     * own headline example of the STUDENTS-vs-APPLICATIONS distinction
     * is preserved exactly: a student with 5 applications counts once
     * in the student figures and 5 times in the application figures.
     *
     * @param array<string, mixed> $filters
     */
    private function buildApplicationSummary(array $filters): Spreadsheet
    {
        $summary = $this->placementReportService->summary($filters);

        $spreadsheet = new Spreadsheet();
        $overview = $spreadsheet->getActiveSheet();
        $overviewTitle = SheetTitleSanitizer::sanitize('Overview');
        $overview->setTitle($overviewTitle);
        $this->writeHeader($overview, ['Metric', 'Count']);
        $this->writeDataRows($overview, 2, [
            ['Total Applications', $summary['total_applications']],
            ['Distinct Students Who Applied', $summary['unique_applicants']],
            ['Avg. Applications per Student', $summary['avg_applications_per_applicant']],
        ]);
        $this->autosize($overview, 2);

        // Same filters as summary() above, so the two sheets always add up.
        $base = Application::query();
        $this->applyDateRange($base, 'applied_at', $filters);
        $this->applyStudentRelationScope($base, $filters);
        $this->applyCompanyScope($base, $filters);
        $this->applyApplicableType($base, $filters);

        $byType = $spreadsheet->createSheet();
        $byType->setTitle(SheetTitleSanitizer::sanitize('By Opportunity Type', [$overviewTitle]));
        $this->writeHeader($byType, ['Opportunity Type', 'Applications', 'Distinct Students']);
        $typeRows = (clone $base)
            ->selectRaw('applicable_type, COUNT(*) as application_count, COUNT(DISTINCT student_id) as student_count')
            ->groupBy('applicable_type')
            ->get()
            ->map(fn ($r) => [$r->applicable_type === Internship::class ? 'Internship' : 'Job', $r->application_count, $r->student_count])
            ->all();
        $this->writeDataRows($byType, 2, $typeRows);
        $this->autosize($byType, 3);

        return $spreadsheet;
    }

    /**
     * Per-status breakdown - now delegated to
     * App\Services\Reporting\ApplicationReportService::funnel(), the
     * exact same current-status distribution the
     * `/api/admin/reports/applications` endpoint returns (see that
     * method's docblock for why this is a live Application.status
     * snapshot, never the historical ApplicationStatusHistory log).
     *
     * @param array<string, mixed> $filters
     */
    private function buildStatusSummary(array $filters): Spreadsheet
    {
        $funnel = $this->applicationReportService->funnel($filters);

        $rows = collect($funnel)
            ->map(fn (array $counts, string $status) => [$status, $counts['applications'], $counts['students']])
            ->values()
            ->all();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Status Summary'));
        $this->writeHeader($sheet, ['Status', 'Applications', 'Distinct Students']);
        $this->writeDataRows($sheet, 2, $rows);
        $this->autosize($sheet, 3);

        return $spreadsheet;
    }

    /**
     * One row per company: total applications, distinct students who
     * applied, and how many of those applications reached SELECTED -
     * the module spec's "company-wise placement report", now delegated
     * to App\Services\Reporting\PlacementReportService::breakdownBy().
     *
     * @param array<string, mixed> $filters
     */
    private function buildCompanyWisePlacementReport(array $filters): Spreadsheet
    {
        $rows = $this->placementBreakdownRows('company', $filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Company-wise Placement'));
        $this->writeHeader($sheet, ['Company', 'Total Applications', 'Distinct Students Applied', 'Students Selected', 'Selection Rate %']);
        $this->writeDataRows($sheet, 2, $rows);
        $this->autosize($sheet, 5);

        return $spreadsheet;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildDepartmentWisePlacementReport(array $filters): Spreadsheet
    {
        $rows = $this->placementBreakdownRows('department', $filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Department-wise Placement'));
        $this->writeHeader($sheet, ['Department', 'Total Applications', 'Distinct Students Applied', 'Students Selected', 'Selection Rate %']);
        $this->writeDataRows($sheet, 2, $rows);
        $this->autosize($sheet, 5);

        return $spreadsheet;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildBatchWisePlacementReport(array $filters): Spreadsheet
    {
        $rows = $this->placementBreakdownRows('batch', $filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Batch-wise Placement'));
        $this->writeHeader($sheet, ['Batch', 'Total Applications', 'Distinct Students Applied', 'Students Selected', 'Selection Rate %']);
        $this->writeDataRows($sheet, 2, $rows);
        $this->autosize($sheet, 5);

        return $spreadsheet;
    }

    /**
     * Departments down the side, batches across the top: students,
     * placed and placement % per batch, so 2023-27 and 2024-28 can be
     * compared at a glance. A Total row closes each batch's columns.
     * Counts come from PlacementReportService::batchComparison().
     *
     * @param array<string, mixed> $filters
     */
    private function buildBatchComparison(array $filters): Spreadsheet
    {
        $rows = collect($this->placementReportService->batchComparison($filters));
        $batches = $rows->pluck('batch')->map(fn ($b) => $b ?? '—')->unique()->sort()->values();
        $departments = $rows->pluck('department')->map(fn ($d) => $d ?? '—')->unique()->sort()->values();
        $cell = $rows->keyBy(fn ($r) => ($r['department'] ?? '—').'|'.($r['batch'] ?? '—'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Batch Comparison'));

        $headers = ['Department'];
        foreach ($batches as $batch) {
            array_push($headers, "{$batch} Students", "{$batch} Placed", "{$batch} Placement %");
        }
        $this->writeHeader($sheet, $headers);

        $rowIndex = 2;
        foreach ($departments as $department) {
            $line = [$department];
            foreach ($batches as $batch) {
                $r = $cell->get("{$department}|{$batch}");
                array_push($line, $r['total_students'] ?? 0, $r['students_placed'] ?? 0, $r ? $r['placement_rate'] : '—');
            }
            $sheet->fromArray($line, null, 'A'.$rowIndex++, true);
        }

        $line = ['Total'];
        foreach ($batches as $batch) {
            $inBatch = $rows->filter(fn ($r) => ($r['batch'] ?? '—') === $batch);
            $total = $inBatch->sum('total_students');
            $placed = $inBatch->sum('students_placed');
            array_push($line, $total, $placed, $total > 0 ? round($placed / $total * 100, 2) : 0.0);
        }
        $sheet->fromArray($line, null, 'A'.$rowIndex, true);
        $sheet->getStyle('A'.$rowIndex.':'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)).$rowIndex)->getFont()->setBold(true);
        $this->autosize($sheet, count($headers));

        return $spreadsheet;
    }

    /**
     * Package statistics in LPA - see App\Services\Reporting\PackageReportService
     * for what counts as an offer and why student-level figures use each
     * student's best offer. Supports the student filters, company_id and
     * date_from/date_to (on the selection / PPO offer date).
     *
     * @param array<string, mixed> $filters
     */
    private function buildPackageReport(array $filters): Spreadsheet
    {
        $report = $this->packageReportService->report($filters);
        $o = $report['overview'];

        $spreadsheet = new Spreadsheet();
        $titles = [SheetTitleSanitizer::sanitize('Overview')];
        $overview = $spreadsheet->getActiveSheet();
        $overview->setTitle($titles[0]);
        $this->writeHeader($overview, ['Metric', 'Value']);
        $this->writeDataRows($overview, 2, [
            ['Students with an offer', $o['students_with_offers']],
            ['Students with a package entered', $o['students_with_package']],
            ['Total offers', $o['offers']],
            ['Offers without a package entered', $o['offers_without_package']],
            ['Highest package (LPA)', $o['highest'] ?? '—'],
            ['Average package (LPA)', $o['average'] ?? '—'],
            ['Median package (LPA)', $o['median'] ?? '—'],
            ['Lowest package (LPA)', $o['lowest'] ?? '—'],
        ]);
        $this->autosize($overview, 2);

        $stat = fn (?float $v) => $v ?? '—';

        $titles[] = SheetTitleSanitizer::sanitize('By Department', $titles);
        $byDepartment = $spreadsheet->createSheet();
        $byDepartment->setTitle(end($titles));
        $this->writeHeader($byDepartment, ['Department', 'Students with Package', 'Highest (LPA)', 'Average (LPA)', 'Median (LPA)', 'Lowest (LPA)']);
        $this->writeDataRows($byDepartment, 2, array_map(fn (array $r) => [$r['department'], $r['students'], $stat($r['highest']), $stat($r['average']), $stat($r['median']), $stat($r['lowest'])], $report['by_department']));
        $this->autosize($byDepartment, 6);

        $titles[] = SheetTitleSanitizer::sanitize('By Company', $titles);
        $byCompany = $spreadsheet->createSheet();
        $byCompany->setTitle(end($titles));
        $this->writeHeader($byCompany, ['Company', 'Offers', 'Offers without Package', 'Highest (LPA)', 'Average (LPA)', 'Median (LPA)', 'Lowest (LPA)']);
        $this->writeDataRows($byCompany, 2, array_map(fn (array $r) => [$r['company'], $r['offers'], $r['offers_without_package'], $stat($r['highest']), $stat($r['average']), $stat($r['median']), $stat($r['lowest'])], $report['by_company']));
        $this->autosize($byCompany, 7);

        $titles[] = SheetTitleSanitizer::sanitize('Best Offer per Student', $titles);
        $best = $spreadsheet->createSheet();
        $best->setTitle(end($titles));
        $this->writeHeader($best, ['JNTU No', 'Name', ...self::STUDENT_HEADERS, 'Company', 'Role', 'Source', 'Package (LPA)', 'Total Offers']);
        $this->writeDataRows($best, 2, array_map(fn (array $r) => [
            $r['student']?->roll_number,
            $r['student']?->user?->name,
            ...$this->studentColumns($r['student']),
            $r['company'],
            $r['role'],
            $r['source'],
            $r['lpa'] === null ? 'Not entered' : round($r['lpa'], 2),
            $r['offers'],
        ], $report['best_offers']));
        $this->autosize($best, 11);

        return $spreadsheet;
    }

    /**
     * Eligible students for the job, internship or placement drive the
     * filters name (job_id / internship_id / drive_id): a Summary sheet
     * with one row per opportunity, then one sheet per opportunity
     * listing every eligible student (applied or not) and every student
     * who applied without meeting the criteria. Student filters narrow
     * the pool. See App\Services\Reporting\EligibilityReportService.
     *
     * @param array<string, mixed> $filters
     */
    private function buildEligibleStudents(array $filters): Spreadsheet
    {
        $opportunities = $this->eligibilityReportService->opportunities($filters);

        if ($opportunities === []) {
            throw new ExcelImportException('Choose a job, internship or placement drive for the Eligible Students export.');
        }

        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $titles = [SheetTitleSanitizer::sanitize('Summary')];
        $summary->setTitle($titles[0]);
        $this->writeHeader($summary, ['Company', 'Role/Title', 'Type', 'Criteria', 'Students Checked', 'Eligible', 'Eligible, Applied', 'Eligible, Not Applied', 'Applied, Not Eligible']);

        foreach ($opportunities as $index => $opportunity) {
            $result = $this->eligibilityReportService->forOpportunity($opportunity, $filters);
            $type = $opportunity instanceof Internship ? 'Internship' : 'Job';

            $summary->fromArray([
                $opportunity->company?->name,
                $opportunity->title,
                $type,
                $this->eligibilityReportService->criteriaText($opportunity),
                $result['summary']['in_scope'],
                $result['summary']['eligible'],
                $result['summary']['eligible_applied'],
                $result['summary']['eligible_not_applied'],
                $result['summary']['applied_not_eligible'],
            ], null, 'A'.($index + 2), true);

            $title = SheetTitleSanitizer::sanitize(trim(($opportunity->company?->name ?? '').' '.$opportunity->title), $titles);
            $titles[] = $title;
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $this->writeHeader($sheet, ['JNTU No', 'Name', ...self::STUDENT_HEADERS, 'CGPA', 'Backlogs', 'Eligible', 'Why Not Eligible', 'Applied', 'Application Status', 'College Email', 'Phone']);
            $this->writeDataRows($sheet, 2, array_map(fn (array $r) => [
                $r['student']->roll_number,
                $r['student']->user?->name,
                ...$this->studentColumns($r['student']),
                $r['student']->cgpa === null ? 'Not yet available' : (float) $r['student']->cgpa,
                $r['student']->backlogs ?? 'Not reported',
                $r['eligible'] ? 'Yes' : 'No',
                implode(', ', $r['reasons']),
                $r['application_status'] ? 'Yes' : 'No',
                $r['application_status'] ?? '—',
                $r['student']->user?->email,
                $r['student']->phone,
            ], $result['rows']));
            $this->autosize($sheet, 14);
        }

        $this->autosize($summary, 9);

        return $spreadsheet;
    }

    /**
     * One row per department + year + section, counted from the students
     * table (PlacementReportService::classBreakdown()), with a Total row
     * at the bottom that equals the sum of the rows above it.
     *
     * @param array<string, mixed> $filters
     */
    private function buildSectionWisePlacementReport(array $filters): Spreadsheet
    {
        $rows = $this->placementReportService->classBreakdown($filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Section-wise Placement'));
        $this->writeHeader($sheet, ['Department', 'Year', 'Section', 'Total Students', 'Students Applied', 'Students Placed', 'Students Unplaced', 'Placement %']);

        $this->writeDataRows($sheet, 2, array_map(fn (array $r) => [
            $r['department'] ?? '—',
            $r['year'] ?? '—',
            $r['section'] ?? '—',
            $r['total_students'],
            $r['students_applied'],
            $r['students_placed'],
            $r['students_unplaced'],
            $r['placement_rate'],
        ], $rows));

        $total = array_sum(array_column($rows, 'total_students'));
        $placed = array_sum(array_column($rows, 'students_placed'));
        $totalRow = count($rows) + 2;
        $sheet->fromArray([
            'Total', null, null,
            $total,
            array_sum(array_column($rows, 'students_applied')),
            $placed,
            array_sum(array_column($rows, 'students_unplaced')),
            $total > 0 ? round(($placed / $total) * 100, 2) : 0.0,
        ], null, 'A'.$totalRow, true);
        $sheet->getStyle('A'.$totalRow.':H'.$totalRow)->getFont()->setBold(true);
        $this->autosize($sheet, 8);

        return $spreadsheet;
    }

    /**
     * Adapts App\Services\Reporting\PlacementReportService::breakdownBy()'s
     * associative row shape (id/label/total_applications/
     * distinct_students/students_selected/selection_rate) to this
     * class's plain positional-array sheet-row convention. The
     * aggregation itself - the join/GROUP BY SQL "Students Selected"
     * counts DISTINCT student_id among SELECTED applications, since a
     * student could theoretically hold more than one SELECTED
     * application across different opportunities and must still count
     * once per group as "placed" - now lives entirely in that service,
     * not here; this is only the presentation adapter.
     *
     * @param array<string, mixed> $filters
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: float}>
     */
    private function placementBreakdownRows(string $dimension, array $filters): array
    {
        return array_map(
            fn (array $row) => [$row['label'], $row['total_applications'], $row['distinct_students'], $row['students_selected'], $row['selection_rate']],
            $this->placementReportService->breakdownBy($dimension, $filters),
        );
    }

    /**
     * One row per student: how many applications they have made and
     * their breakdown by status, plus their most recent application's
     * status - the module spec's "student application report".
     *
     * @param array<string, mixed> $filters
     */
    private function buildStudentApplicationReport(array $filters): Spreadsheet
    {
        $studentQuery = Student::query()->with(['user', 'department', 'batch']);
        $this->applyStudentOwnScope($studentQuery, $filters);
        $this->applyStudentOutcome($studentQuery, $filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Student Application Report'));
        $this->writeHeader($sheet, ['JNTU No', 'Name', ...self::STUDENT_HEADERS, 'Total Applications', 'Selected', 'Rejected', 'Withdrawn', 'In Progress', 'Latest Status']);

        $rowIndex = 2;

        $studentQuery->chunkById(self::CHUNK_SIZE, function ($students) use ($sheet, &$rowIndex) {
            $studentIds = $students->pluck('id')->all();

            $counts = Application::query()
                ->whereIn('student_id', $studentIds)
                ->selectRaw('student_id, status, COUNT(*) as c')
                ->groupBy('student_id', 'status')
                ->get()
                // ->status is cast to the ApplicationStatus BackedEnum
                // on the model even from this raw-aggregated row; it
                // must be flattened to its plain string value BEFORE
                // pluck('c', 'status') below, since a BackedEnum
                // instance is not a legal PHP array key.
                ->map(fn ($r) => (object) ['student_id' => $r->student_id, 'status' => $r->status instanceof \BackedEnum ? $r->status->value : $r->status, 'c' => $r->c])
                ->groupBy('student_id');

            $latest = Application::query()
                ->whereIn('student_id', $studentIds)
                ->orderByDesc('applied_at')
                ->get(['student_id', 'status', 'applied_at'])
                ->groupBy('student_id')
                ->map(fn ($rows) => $rows->first());

            foreach ($students as $student) {
                $byStatus = ($counts->get($student->id) ?? collect())->pluck('c', 'status');
                $total = $byStatus->sum();
                $inProgress = $total - ($byStatus->get('SELECTED', 0) + $byStatus->get('REJECTED', 0) + $byStatus->get('WITHDRAWN', 0));

                $sheet->fromArray([
                    $student->roll_number,
                    $student->user?->name,
                    ...$this->studentColumns($student),
                    $total,
                    $byStatus->get('SELECTED', 0),
                    $byStatus->get('REJECTED', 0),
                    $byStatus->get('WITHDRAWN', 0),
                    max($inProgress, 0),
                    $latest->get($student->id)?->status?->value ?? '—',
                ], null, 'A'.$rowIndex, true);
                $rowIndex++;
            }
        });

        $this->autosize($sheet, 12);

        return $spreadsheet;
    }

    /**
     * Every student with zero SELECTED application - the module spec's
     * "unplaced student report". Uses a NOT EXISTS-shaped
     * whereDoesntHave() rather than pulling every student and every
     * application into PHP to compute the complement there.
     *
     * @param array<string, mixed> $filters
     */
    private function buildUnplacedStudentReport(array $filters): Spreadsheet
    {
        $query = Student::query()
            ->with(['user', 'department', 'batch'])
            ->withCount(['applications', 'applications as in_progress_count' => fn (Builder $q) => $q->whereNotIn('status', ['REJECTED', 'WITHDRAWN'])])
            ->whereDoesntHave('applications', fn (Builder $q) => $q->where('status', 'SELECTED'));
        $this->applyStudentOwnScope($query, $filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Unplaced Students'));
        $this->writeHeader($sheet, ['JNTU No', 'Name', ...self::STUDENT_HEADERS, 'Total Applications', 'Applications In Progress']);

        $this->streamRows($sheet, $query, fn (Student $s) => [
            $s->roll_number,
            $s->user?->name,
            ...$this->studentColumns($s),
            $s->applications_count,
            $s->in_progress_count,
        ]);

        return $spreadsheet;
    }

    /**
     * One row per application status-history entry - a chronological
     * activity log ("what changed, when, by whom") rather than a
     * point-in-time snapshot, which is what distinguishes this from
     * buildStudentApplicationReport() above.
     *
     * @param array<string, mixed> $filters
     */
    private function buildStudentApplicationActivity(array $filters): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(SheetTitleSanitizer::sanitize('Application Activity'));
        $this->writeHeader($sheet, ['JNTU No', 'Student Name', ...self::STUDENT_HEADERS, 'Company', 'Role/Title', 'From Status', 'To Status', 'Changed By', 'Changed At', 'Remarks']);

        $query = ApplicationStatusHistory::query()->with(['application.student.user', 'application.student.department', 'application.student.batch', 'application.applicable.company', 'changedBy']);
        $this->applyDateRange($query, 'created_at', $filters);
        $this->applyStudentRelationScope($query, $filters, 'application.student');

        $this->streamRows($sheet, $query, function (ApplicationStatusHistory $h) {
            $application = $h->application;
            $opportunity = $application?->applicable;

            return [
                $application?->student?->roll_number,
                $application?->student?->user?->name,
                ...$this->studentColumns($application?->student),
                $opportunity?->company?->name,
                $opportunity?->title,
                $h->from_status?->value ?? '—',
                $h->to_status->value,
                $h->changedBy?->name,
                $h->created_at?->toDateTimeString(),
                $h->remarks,
            ];
        });

        return $spreadsheet;
    }

    // =========================================================================
    // shared filter / write helpers
    // =========================================================================

    /**
     * Narrows a Student query by `student_status` (see STUDENT_STATUSES).
     *
     * @param array<string, mixed> $filters
     */
    private function applyStudentOutcome(Builder $query, array $filters): void
    {
        $status = $filters['student_status'] ?? null;

        if (! $status) {
            return;
        }

        $negate = str_starts_with($status, 'not_') || $status === 'unplaced';
        $condition = match ($status) {
            'applied', 'not_applied' => null,
            'shortlisted', 'not_shortlisted' => fn (Builder $q) => $q->whereHas('statusHistory', fn (Builder $h) => $h->where('to_status', 'SHORTLISTED')),
            'interviewed', 'not_interviewed' => fn (Builder $q) => $q->has('interviews'),
            'placed', 'unplaced' => fn (Builder $q) => $q->where('status', 'SELECTED'),
        };

        $negate
            ? $query->whereDoesntHave('applications', $condition)
            : $query->whereHas('applications', $condition);
    }

    /**
     * The STUDENT_HEADERS cells for one student (blank when the record
     * has no student, e.g. a deleted account).
     *
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?string}
     */
    private function studentColumns(?Student $student): array
    {
        return [
            $student?->department?->name,
            $student?->batch?->name,
            $student?->year,
            $student?->section,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function writeHeader(Worksheet $sheet, array $headers): void
    {
        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $range = 'A1:'.$lastColumn.'1';
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->freezePane('A2');
    }

    /**
     * Streams an Eloquent query's results directly into the sheet via
     * chunkById() (module spec section 27: "use chunking... avoid
     * loading everything into memory"), rather than ->get()->map(...) -
     * the reason every raw/analytical export in this class that can
     * grow large (anything per-application, per-attendance-record, or
     * per-status-history-entry) uses this helper instead of building an
     * in-memory array first.
     *
     * @param Builder $query
     * @param callable(mixed): list<mixed> $rowMapper
     */
    private function streamRows(Worksheet $sheet, Builder $query, callable $rowMapper): void
    {
        $rowIndex = 2;
        $columnCount = 0;

        $query->chunkById(self::CHUNK_SIZE, function ($models) use ($sheet, $rowMapper, &$rowIndex, &$columnCount) {
            foreach ($models as $model) {
                $row = $rowMapper($model);
                $columnCount = max($columnCount, count($row));
                $sheet->fromArray($row, null, 'A'.$rowIndex, true);
                $rowIndex++;
            }
        });

        if ($columnCount > 0) {
            $this->autosize($sheet, $columnCount);
        }
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function writeDataRows(Worksheet $sheet, int $startRow, array $rows): void
    {
        foreach ($rows as $offset => $row) {
            $sheet->fromArray($row, null, 'A'.($startRow + $offset), true);
        }
    }

    private function autosize(Worksheet $sheet, int $columnCount): void
    {
        for ($i = 1; $i <= $columnCount; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }
}
