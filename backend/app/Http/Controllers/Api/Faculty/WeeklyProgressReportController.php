<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Enums\WeeklyReportStatus;
use App\Exceptions\WeeklyProgressReportException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyProgressReport\ReviewWeeklyProgressReportRequest;
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
 * A faculty member's own weekly-report review workflow. PRODUCT RULE -
 * do not weaken this: index()/forAssignment() are unconditionally
 * scoped to `internshipAssignment.faculty_id = the authenticated
 * faculty's OWN Faculty record id` (never the User id - see
 * WeeklyProgressReportPolicy's docblock), so a faculty member can never
 * see or review reports for another faculty's students - only what is
 * explicitly assigned to them. Even when a student_id filter is
 * supplied to index(), it is applied as an ADDITIONAL constraint on top
 * of that base scope, never in place of it, so it can never be used to
 * reach another faculty's student.
 *
 * There is deliberately no store()/update() action here at all - a
 * faculty member never creates a report or edits its content, only
 * moves it through the review lifecycle via review() (see
 * WeeklyProgressReportPolicy's docblock and
 * App\Services\WeeklyProgressReportService::startReview()/approve()/
 * requestChanges(), which never touch the report's own content
 * fields).
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
        $faculty = $request->user()->faculty;

        if ($faculty === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Weekly reports retrieved successfully.',
            );
        }

        $query = WeeklyProgressReport::query()
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
                });
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

    public function review(ReviewWeeklyProgressReportRequest $request, WeeklyProgressReport $report): JsonResponse
    {
        Gate::authorize('review', $report);

        $action = WeeklyReportStatus::from($request->validated('action'));
        $remarks = $request->validated('faculty_review_remarks');

        try {
            $report = match ($action) {
                WeeklyReportStatus::UNDER_REVIEW => $this->reportService->startReview($report, $request->user()),
                WeeklyReportStatus::APPROVED => $this->reportService->approve($report, $request->user(), $remarks),
                WeeklyReportStatus::CHANGES_REQUESTED => $this->reportService->requestChanges($report, $request->user(), (string) $remarks),
                default => throw new WeeklyProgressReportException('Unsupported review action.'),
            };
        } catch (WeeklyProgressReportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $report->load([...self::EAGER_LOAD, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new WeeklyProgressReportResource($report), message: 'Weekly report review updated successfully.');
    }
}
