<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceFilterRequest;
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
 * A student's own attendance - list, single-record detail, and the
 * per-assignment daily records + summary. Ownership is always derived
 * by walking attendance -> internshipAssignment -> student_id (see
 * AttendancePolicy::studentOwns()) and, for the assignment-scoped
 * endpoint, InternshipAssignmentPolicy::view()'s studentOwns() addition
 * - NEVER trusted from the URL/request, and the authenticated
 * student's own id/record is always used, never accepted as a
 * parameter. No create/update/delete routes exist on this controller
 * at all - a student can only ever read.
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
        $student = $request->user()->student;

        if ($student === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Attendance retrieved successfully.',
            );
        }

        $query = Attendance::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $student->id))
            ->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
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

        $attendance->load(self::EAGER_LOAD);

        return $this->success(data: new AttendanceResource($attendance), message: 'Attendance retrieved successfully.');
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

        return $this->summaryResponse([
            'assignment' => new InternshipAssignmentListResource($assignment),
            'summary' => new AttendanceSummaryResource($this->attendanceService->getAssignmentAttendanceSummary($assignment)),
            'records' => AttendanceListResource::collection($records),
        ]);
    }

    /**
     * The same {success, message, data} envelope
     * FormatsApiResponse::success() builds for forAssignment(), but
     * encoded directly with JSON_PRESERVE_ZERO_FRACTION from the
     * start. attendance_percentage (AttendanceSummaryResource) is a
     * genuine PHP float that can legitimately land on a whole number
     * - 0 or 100 - and PHP's default JSON encoding collapses a
     * whole-number float to "100" instead of "100.0" unless that flag
     * is active at the moment of the actual encode call.
     *
     * Setting the flag AFTER success() has already built the response
     * does not work: Symfony's JsonResponse::setEncodingOptions()
     * re-decodes the response's already-encoded JSON string to
     * re-apply the new options, but by then a whole-number percentage
     * has already been written out as the bare integer "100" (using
     * the default options active at the first encode) and decodes
     * back as a PHP int, not a float - re-encoding an int with
     * JSON_PRESERVE_ZERO_FRACTION changes nothing, since the flag
     * only affects genuine float values. Building the response in one
     * pass, with the flag already active, avoids that lossy
     * round-trip.
     *
     * Scoped to just this one response, not the shared
     * FormatsApiResponse trait every controller in this codebase
     * uses, since no other endpoint returns a raw float that needs
     * it.
     */
    private function summaryResponse(array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Attendance retrieved successfully.',
            'data' => $data,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
