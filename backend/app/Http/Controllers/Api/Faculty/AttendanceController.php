<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceFilterRequest;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRequest;
use App\Http\Resources\AttendanceListResource;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\AttendanceSummaryResource;
use App\Http\Resources\InternshipAssignmentListResource;
use App\Models\Attendance;
use App\Models\InternshipAssignment;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A faculty member's own attendance-marking workflow. PRODUCT RULE -
 * do not weaken this: index()/forAssignment() are unconditionally
 * scoped to `internshipAssignment.faculty_id = the authenticated
 * faculty's OWN Faculty record id` (never the User id - see
 * AttendancePolicy's docblock), so a faculty member can never see or
 * mark attendance for another faculty's students - only what is
 * explicitly assigned to them. Even when a student_id filter is
 * supplied to index(), it is applied as an ADDITIONAL constraint on
 * top of that base scope, never in place of it, so it can never be
 * used to reach another faculty's student.
 *
 * store()/update() never accept student_id/faculty_id/
 * internship_assignment_id from the request body - both are always
 * derived from the route-bound, ownership-checked InternshipAssignment
 * (store) or the route-bound Attendance's own assignment (update) -
 * see StoreAttendanceRequest/UpdateAttendanceRequest and
 * AttendanceService, which is the only writer.
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
        $faculty = $request->user()->faculty;

        if ($faculty === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Attendance retrieved successfully.',
            );
        }

        $query = Attendance::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $faculty->id))
            ->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            // ANDed onto the faculty-scoped base query above - a
            // student_id belonging to another faculty's assignment
            // simply matches zero rows here, it can never widen the
            // scope (see this class's own docblock).
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($fromDate = $request->validated('from_date')) {
            $query->where('attendance_date', '>=', $fromDate);
        }

        if ($toDate = $request->validated('to_date')) {
            $query->where('attendance_date', '<=', $toDate);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->validated('search', ''))) {
            $query->whereHas('internshipAssignment', function ($q) use ($search) {
                $q->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                });
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

    public function forAssignment(InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);

        $assignment->load(['student.user', 'student.department', 'faculty.user', 'internship.company']);

        $records = Attendance::query()
            ->where('internship_assignment_id', $assignment->id)
            ->with(self::EAGER_LOAD)
            ->orderByDesc('attendance_date')
            ->get();

        return $this->success(
            data: [
                'assignment' => new InternshipAssignmentListResource($assignment),
                'summary' => new AttendanceSummaryResource($this->attendanceService->getAssignmentAttendanceSummary($assignment)),
                'records' => AttendanceListResource::collection($records),
            ],
            message: 'Attendance retrieved successfully.',
        );
    }

    public function store(StoreAttendanceRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('create', [Attendance::class, $assignment]);

        try {
            $attendance = $this->attendanceService->markAttendance($assignment, $request->user(), $request->validated());
        } catch (AttendanceException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $attendance->load(self::EAGER_LOAD);

        return $this->success(data: new AttendanceResource($attendance), message: 'Attendance marked successfully.', status: 201);
    }

    public function update(UpdateAttendanceRequest $request, Attendance $attendance): JsonResponse
    {
        Gate::authorize('update', $attendance);

        try {
            $attendance = $this->attendanceService->updateAttendance($attendance, $request->validated(), $request->user());
        } catch (AttendanceException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $attendance->load(self::EAGER_LOAD);

        return $this->success(data: new AttendanceResource($attendance), message: 'Attendance updated successfully.');
    }
}
