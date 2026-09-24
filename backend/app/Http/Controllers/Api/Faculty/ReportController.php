<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Reporting\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/faculty/reports/summary - a faculty member's own workload
 * snapshot (their own assigned students, pending review queues). The
 * acting Faculty is always resolved from the authenticated user
 * ($request->user()->faculty()->firstOrFail()), mirroring
 * Api\Dashboard\FacultyDashboardController - no faculty_id parameter
 * exists on this controller, so a faculty member can never request
 * another faculty member's workload by id.
 */
class ReportController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly DashboardAnalyticsService $dashboardAnalyticsService) {}

    public function summary(Request $request): JsonResponse
    {
        $faculty = $request->user()->faculty()->firstOrFail();

        return $this->success($this->dashboardAnalyticsService->forFaculty($faculty));
    }
}
