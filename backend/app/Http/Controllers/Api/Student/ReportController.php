<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Reporting\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/student/reports/summary - a student's own application/
 * interview/attendance snapshot. The acting Student is always resolved
 * from the authenticated user ($request->user()->student()->firstOrFail()),
 * exactly mirroring Api\Dashboard\StudentDashboardController - there is
 * no student_id route/query parameter anywhere on this controller, so
 * a student can never request another student's report by id (IDOR-safe
 * by construction, not by an authorization check that could be
 * forgotten).
 */
class ReportController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly DashboardAnalyticsService $dashboardAnalyticsService) {}

    public function summary(Request $request): JsonResponse
    {
        $student = $request->user()->student()->firstOrFail();

        return $this->success($this->dashboardAnalyticsService->forStudent($student));
    }
}
