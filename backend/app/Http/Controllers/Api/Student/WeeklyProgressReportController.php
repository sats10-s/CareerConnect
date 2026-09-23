<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\WeeklyProgressReportException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyProgressReport\StoreWeeklyProgressReportRequest;
use App\Http\Requests\WeeklyProgressReport\SubmitWeeklyProgressReportRequest;
use App\Http\Requests\WeeklyProgressReport\UpdateWeeklyProgressReportRequest;
use App\Http\Requests\WeeklyProgressReport\WeeklyProgressReportFilterRequest;
use App\Http\Resources\InternshipAssignmentListResource;
use App\Http\Resources\WeeklyProgressReportListResource;
use App\Http\Resources\WeeklyProgressReportResource;
use App\Models\InternshipAssignment;
use App\Models\WeeklyProgressReport;
use App\Services\WeeklyProgressReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A student's own weekly progress reports - list, single-report
 * detail, the per-assignment report list, creating a draft, updating a
 * draft, and submitting. Ownership is always derived by walking
 * report -> internshipAssignment -> student_id (see
 * WeeklyProgressReportPolicy::studentOwns()) and, for the
 * assignment-scoped endpoint,
 * InternshipAssignmentPolicy::view()'s studentOwns() branch - NEVER
 * trusted from the URL/request, and the authenticated student's own
 * id/record is always used, never accepted as a parameter. There is no
 * review()/approve() action here at all - reviewing is exclusively a
 * faculty action (see Api\Faculty\WeeklyProgressReportController).
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

    public function __construct(private readonly WeeklyProgressReportService $reportService) {}

    public function index(WeeklyProgressReportFilterRequest $request): JsonResponse
    {
        $student = $request->user()->student;

        if ($student === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Weekly reports retrieved successfully.',
            );
        }

        $query = WeeklyProgressReport::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $student->id))
            ->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
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

        $report->load(self::EAGER_LOAD);

        return $this->success(data: new WeeklyProgressReportResource($report), message: 'Weekly report retrieved successfully.');
    }

    public function forAssignment(InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);

        $assignment->load(['student.user', 'student.department', 'faculty.user', 'internship.company']);

        $records = WeeklyProgressReport::query()
            ->where('internship_assignment_id', $assignment->id)
            ->with(self::EAGER_LOAD)
            ->orderByDesc('week_number')
            ->get();

        return $this->success(
            data: [
                'assignment' => new InternshipAssignmentListResource($assignment),
                'records' => WeeklyProgressReportListResource::collection($records),
            ],
            message: 'Weekly reports retrieved successfully.',
        );
    }

    public function store(StoreWeeklyProgressReportRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('create', [WeeklyProgressReport::class, $assignment]);

        try {
            $report = $this->reportService->createDraft($assignment, $request->user(), $request->validated());
        } catch (WeeklyProgressReportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $report->load(self::EAGER_LOAD);

        return $this->success(data: new WeeklyProgressReportResource($report), message: 'Weekly report draft created successfully.', status: 201);
    }

    public function update(UpdateWeeklyProgressReportRequest $request, WeeklyProgressReport $report): JsonResponse
    {
        Gate::authorize('update', $report);

        try {
            $report = $this->reportService->updateDraft($report, $request->validated(), $request->user());
        } catch (WeeklyProgressReportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $report->load(self::EAGER_LOAD);

        return $this->success(data: new WeeklyProgressReportResource($report), message: 'Weekly report updated successfully.');
    }

    public function submit(SubmitWeeklyProgressReportRequest $request, WeeklyProgressReport $report): JsonResponse
    {
        Gate::authorize('submit', $report);

        try {
            $report = $this->reportService->submit($report, $request->user());
        } catch (WeeklyProgressReportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $report->load(self::EAGER_LOAD);

        return $this->success(data: new WeeklyProgressReportResource($report), message: 'Weekly report submitted successfully.');
    }
}
