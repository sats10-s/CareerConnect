<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ApplicationTrendsRequest;
use App\Http\Requests\Reports\AttendanceReportFilterRequest;
use App\Http\Requests\Reports\PlacementBreakdownRequest;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reporting\ApplicationReportService;
use App\Services\Reporting\AttendanceReportService;
use App\Services\Reporting\DashboardAnalyticsService;
use App\Services\Reporting\FacultyReportService;
use App\Services\Reporting\InternshipReportService;
use App\Services\Reporting\PlacementReportService;
use Illuminate\Http\JsonResponse;

/**
 * Reports & Analytics module - the institution-wide report endpoints
 * for PLACEMENT_ADMIN and SUPER_ADMIN, mounted twice in routes/api.php
 * (`/api/admin/reports/*` and `/api/super-admin/reports/*` - see that
 * file) at this SAME controller class, per this module's decision
 * not to duplicate a controller purely to serve an identically-scoped
 * institution-wide report under a different URL prefix. Both mounts
 * carry `role:PLACEMENT_ADMIN,SUPER_ADMIN` (the admin prefix) or
 * `role:SUPER_ADMIN` (the super-admin prefix) middleware - there is no
 * per-record ownership to check here (an institution-wide report has
 * no "owner"), so authorization is entirely the route middleware's
 * job, mirroring how Api\Dashboard\AdminDashboardController /
 * SuperAdminDashboardController already have no Gate/Policy call of
 * their own.
 *
 * No Faculty Evaluation/scoring endpoint exists here or anywhere in
 * this module - explicitly out of scope per the module spec.
 */
class ReportController extends Controller
{
    use FormatsApiResponse;

    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
        private readonly PlacementReportService $placementReportService,
        private readonly ApplicationReportService $applicationReportService,
        private readonly InternshipReportService $internshipReportService,
        private readonly AttendanceReportService $attendanceReportService,
        private readonly FacultyReportService $facultyReportService,
    ) {}

    public function dashboard(ReportFilterRequest $request): JsonResponse
    {
        return $this->success($this->dashboardAnalyticsService->forAdmin($request->filters()));
    }

    /**
     * The placement summary plus, when a `dimension` is given (or by
     * default, company-wise), its institution-level breakdown - one
     * response rather than two separate routes, since a consumer
     * almost always wants both together.
     */
    public function placements(PlacementBreakdownRequest $request): JsonResponse
    {
        $breakdown = $this->placementReportService->breakdownBy($request->dimension(), $request->filters());
        $breakdown = $this->sortBreakdown($breakdown, $request->sort(), $request->direction());

        return $this->success([
            'summary' => $this->placementReportService->summary($request->filters()),
            'breakdown' => [
                'dimension' => $request->dimension(),
                'rows' => $breakdown,
            ],
        ]);
    }

    public function applications(ReportFilterRequest $request): JsonResponse
    {
        return $this->success([
            'funnel' => $this->applicationReportService->funnel($request->filters()),
        ]);
    }

    public function internships(ReportFilterRequest $request): JsonResponse
    {
        return $this->success($this->internshipReportService->summary($request->filters()));
    }

    /**
     * Full per-assignment attendance summary plus, when `threshold` is
     * given, the below-threshold subset of that same data (see
     * App\Services\Reporting\AttendanceReportService::belowThreshold()'s
     * docblock for why it is derived from, not computed separately
     * from, the summary list).
     */
    public function attendance(AttendanceReportFilterRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $summary = $this->attendanceReportService->perAssignmentSummary($filters);
        $threshold = $request->threshold();

        return $this->success([
            'items' => $summary,
            'below_threshold' => [
                'threshold' => $threshold,
                'rows' => array_values(array_filter($summary, fn (array $row) => $row['attendance_percentage'] < $threshold)),
            ],
        ]);
    }

    public function faculty(ReportFilterRequest $request): JsonResponse
    {
        return $this->success([
            'items' => $this->facultyReportService->workload($request->filters()),
        ]);
    }

    /**
     * Company-wise placement analytics - the same breakdownBy('company')
     * aggregation the `placements` endpoint above can also return, but
     * exposed under its own named route to match the module spec's
     * explicit "company analytics" report.
     */
    public function companies(ReportFilterRequest $request): JsonResponse
    {
        $rows = $this->placementReportService->breakdownBy('company', $request->filters());

        return $this->success([
            'companies_count' => count($rows),
            'rows' => $rows,
        ]);
    }

    public function trends(ApplicationTrendsRequest $request): JsonResponse
    {
        $filters = array_merge($request->filters(), ['granularity' => $request->granularity()]);

        return $this->success([
            'granularity' => $request->granularity(),
            'points' => $this->applicationReportService->trends($filters),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortBreakdown(array $rows, string $sort, string $direction): array
    {
        usort($rows, function (array $a, array $b) use ($sort) {
            return $a[$sort] <=> $b[$sort];
        });

        if ($direction === 'desc') {
            $rows = array_reverse($rows);
        }

        return array_values($rows);
    }
}
