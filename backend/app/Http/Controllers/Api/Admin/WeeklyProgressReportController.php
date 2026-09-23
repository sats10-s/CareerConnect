<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyProgressReport\WeeklyProgressReportFilterRequest;
use App\Http\Resources\AdminWeeklyProgressReportResource;
use App\Http\Resources\WeeklyProgressReportListResource;
use App\Models\WeeklyProgressReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin visibility into weekly progress reports across the
 * system. Route-gated by auth:sanctum + role:PLACEMENT_ADMIN,
 * SUPER_ADMIN (see routes/api.php); WeeklyProgressReportPolicy adds the
 * object-level authorization layer on top of that route gate, same
 * pattern as Admin\AttendanceController.
 *
 * There is deliberately NO admin create/update/submit/review action at
 * all - admin gets read-only visibility (index()/show()) and nothing
 * else. Per the module spec's own "Admin should NOT normally edit the
 * student's work content" and "Do not add unnecessary admin write
 * privileges": no administrative correction endpoint is implemented in
 * this increment (see this module's final report for that explicit
 * limitation).
 */
class WeeklyProgressReportController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.student.department',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship.company',
    ];

    public function index(WeeklyProgressReportFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', WeeklyProgressReport::class);

        $query = WeeklyProgressReport::query()->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($facultyId = (int) $request->validated('faculty_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $facultyId));
        }

        if ($departmentId = (int) $request->validated('department_id')) {
            $query->whereHas('internshipAssignment.student', fn ($q) => $q->where('department_id', $departmentId));
        }

        if ($companyId = (int) $request->validated('company_id')) {
            $query->whereHas('internshipAssignment.internship', fn ($q) => $q->where('company_id', $companyId));
        }

        if ($internshipId = (int) $request->validated('internship_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('internship_id', $internshipId));
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($weekNumber = (int) $request->validated('week_number')) {
            $query->where('week_number', $weekNumber);
        }

        if ($fromDate = $request->validated('from_date')) {
            $query->where('week_start_date', '>=', $fromDate);
        }

        if ($toDate = $request->validated('to_date')) {
            $query->where('week_start_date', '<=', $toDate);
        }

        if ($search = trim((string) $request->validated('search', ''))) {
            $query->whereHas('internshipAssignment', function ($q) use ($search) {
                $q->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                })
                    ->orWhereHas('faculty.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('internship.company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->validated('per_page', 15), 1), 100);
        $records = $query->orderByDesc('week_start_date')->paginate($perPage);

        return $this->success(
            data: [
                'items' => WeeklyProgressReportListResource::collection($records->items()),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
            message: 'Weekly reports retrieved successfully.',
        );
    }

    public function show(WeeklyProgressReport $report): JsonResponse
    {
        Gate::authorize('view', $report);

        $report->load([...self::EAGER_LOAD, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminWeeklyProgressReportResource($report), message: 'Weekly report retrieved successfully.');
    }
}
