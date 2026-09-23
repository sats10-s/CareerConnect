<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceFilterRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRequest;
use App\Http\Resources\AdminAttendanceResource;
use App\Http\Resources\AttendanceListResource;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin visibility and correction of attendance across the
 * system. Route-gated by auth:sanctum + role:PLACEMENT_ADMIN,
 * SUPER_ADMIN (see routes/api.php); AttendancePolicy adds the
 * object-level authorization layer on top of that route gate, same
 * pattern as Admin\InternshipAssignmentController.
 *
 * There is deliberately NO admin create/store action - marking new
 * attendance is exclusively a faculty action (see the module spec's
 * section 15's route list, which has no POST); admin may only view and
 * correct (PATCH) existing records. update() passes
 * $restrictToActiveAssignment: false to AttendanceService - unlike an
 * ordinary faculty correction, an admin correction remains available
 * even after the assignment has been completed or cancelled (see the
 * module spec's section 21/22).
 */
class AttendanceController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.student.department',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship.company',
    ];

    public function __construct(private readonly AttendanceService $attendanceService) {}

    public function index(AttendanceFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Attendance::class);

        $query = Attendance::query()->with(self::EAGER_LOAD);

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

        if ($fromDate = $request->validated('from_date')) {
            $query->where('attendance_date', '>=', $fromDate);
        }

        if ($toDate = $request->validated('to_date')) {
            $query->where('attendance_date', '<=', $toDate);
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
        $records = $query->orderByDesc('attendance_date')->paginate($perPage);

        return $this->success(
            data: [
                'items' => AttendanceListResource::collection($records->items()),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
            message: 'Attendance retrieved successfully.',
        );
    }

    public function show(Attendance $attendance): JsonResponse
    {
        Gate::authorize('view', $attendance);

        $this->loadDetail($attendance);

        return $this->success(data: new AdminAttendanceResource($attendance), message: 'Attendance retrieved successfully.');
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance): JsonResponse
    {
        Gate::authorize('update', $attendance);

        try {
            $attendance = $this->attendanceService->updateAttendance($attendance, $request->validated(), $request->user(), restrictToActiveAssignment: false);
        } catch (AttendanceException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($attendance);

        return $this->success(data: new AdminAttendanceResource($attendance), message: 'Attendance updated successfully.');
    }

    private function loadDetail(Attendance $attendance): void
    {
        $attendance->load([
            ...self::EAGER_LOAD,
            'markedBy',
            'history' => fn ($q) => $q->orderBy('changed_at')->with('changedBy'),
        ]);
    }
}
