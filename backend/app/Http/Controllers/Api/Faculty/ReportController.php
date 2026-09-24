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

    /**
     * Overrides FormatsApiResponse::success() for this controller only,
     * to add JSON_PRESERVE_ZERO_FRACTION - see
     * Api\Admin\ReportController::success()'s docblock for the full
     * root-cause explanation (a whole-number PHP float, by default, is
     * emitted by json_encode() without its decimal point, and
     * json_decode() then reads it back as an int).
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
        $faculty = $request->user()->faculty()->firstOrFail();

        return $this->success($this->dashboardAnalyticsService->forFaculty($faculty));
    }
}
