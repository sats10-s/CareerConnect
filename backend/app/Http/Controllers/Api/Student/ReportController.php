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

    /**
     * Overrides FormatsApiResponse::success() for this controller only,
     * to add JSON_PRESERVE_ZERO_FRACTION - see
     * Api\Admin\ReportController::success()'s docblock for the full
     * root-cause explanation (a whole-number PHP float like 0.0 is, by
     * default, emitted by json_encode() as the JSON text "0", which
     * json_decode() then reads back as int 0, not float 0.0). This
     * controller's forStudent() payload includes the same kind of
     * per-assignment `attendance_percentage` ratio field
     * (App\Services\Reporting\AttendanceReportService::perAssignmentSummary())
     * that motivated the identical override there.
     */
    protected function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function summary(Request $request): JsonResponse
    {
        $student = $request->user()->student()->firstOrFail();

        return $this->success($this->dashboardAnalyticsService->forStudent($student));
    }
}
