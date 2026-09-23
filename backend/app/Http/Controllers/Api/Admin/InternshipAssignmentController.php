<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InternshipAssignmentException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivateInternshipAssignmentRequest;
use App\Http\Requests\Admin\CancelInternshipAssignmentRequest;
use App\Http\Requests\Admin\CompleteInternshipAssignmentRequest;
use App\Http\Requests\Admin\ReassignInternshipAssignmentRequest;
use App\Http\Requests\Admin\StoreInternshipAssignmentRequest;
use App\Http\Resources\InternshipAssignmentListResource;
use App\Http\Resources\InternshipAssignmentResource;
use App\Models\Application;
use App\Models\Faculty;
use App\Models\InternshipAssignment;
use App\Services\InternshipAssignmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin management of internship assignments. Route-gated
 * by auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN (see
 * routes/api.php); InternshipAssignmentPolicy adds the object-level
 * authorization layer on top of that route gate, same pattern as
 * Admin\InterviewController/Admin\PlacementDriveController. Every
 * lifecycle write goes through App\Services\InternshipAssignmentService,
 * never a direct ->update() here.
 */
class InternshipAssignmentController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD_DETAIL = [
        'student.user', 'student.department', 'student.batch',
        'faculty.user',
        'internship.company',
        'application',
        'assignedBy',
    ];

    public function __construct(
        private readonly InternshipAssignmentService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', InternshipAssignment::class);

        $query = InternshipAssignment::query()
            ->with(['student.user', 'student.department', 'faculty.user', 'internship.company']);

        if ($studentId = (int) $request->input('student_id')) {
            $query->where('student_id', $studentId);
        }

        if ($facultyId = (int) $request->input('faculty_id')) {
            $query->where('faculty_id', $facultyId);
        }

        if ($internshipId = (int) $request->input('internship_id')) {
            $query->where('internship_id', $internshipId);
        }

        if ($companyId = (int) $request->input('company_id')) {
            $query->whereHas('internship', fn ($q) => $q->where('company_id', $companyId));
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('assigned_at', '>=', Carbon::parse($dateFrom));
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('assigned_at', '<=', Carbon::parse($dateTo));
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                })
                    ->orWhereHas('faculty.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('internship.company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $assignments = $query->latest('assigned_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => InternshipAssignmentListResource::collection($assignments->items()),
                'meta' => [
                    'current_page' => $assignments->currentPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                    'last_page' => $assignments->lastPage(),
                ],
            ],
            message: 'Internship assignments retrieved successfully.',
        );
    }

    public function store(StoreInternshipAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', InternshipAssignment::class);

        $application = Application::findOrFail($request->validated('application_id'));
        $faculty = Faculty::findOrFail($request->validated('faculty_id'));

        try {
            $assignment = $this->service->create(
                $application,
                $faculty,
                $request->user(),
                $request->validated('assigned_at'),
                $request->validated('notes'),
            );
        } catch (InternshipAssignmentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($assignment);

        return $this->success(data: new InternshipAssignmentResource($assignment), message: 'Internship assignment created successfully.', status: 201);
    }

    public function show(InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);

        $this->loadDetail($assignment);

        return $this->success(data: new InternshipAssignmentResource($assignment), message: 'Internship assignment retrieved successfully.');
    }

    public function reassignFaculty(ReassignInternshipAssignmentRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);

        $newFaculty = Faculty::findOrFail($request->validated('faculty_id'));

        try {
            $assignment = $this->service->reassignFaculty($assignment, $newFaculty, $request->user(), $request->validated('remarks'));
        } catch (InternshipAssignmentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($assignment);

        return $this->success(data: new InternshipAssignmentResource($assignment), message: 'Faculty reassigned successfully.');
    }

    public function activate(ActivateInternshipAssignmentRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);

        try {
            $assignment = $this->service->activate($assignment, $request->user(), $request->validated('started_at'));
        } catch (InternshipAssignmentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($assignment);

        return $this->success(data: new InternshipAssignmentResource($assignment), message: 'Internship assignment activated successfully.');
    }

    public function complete(CompleteInternshipAssignmentRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);

        try {
            $assignment = $this->service->complete($assignment, $request->user(), $request->validated('remarks'), $request->validated('ended_at'));
        } catch (InternshipAssignmentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($assignment);

        return $this->success(data: new InternshipAssignmentResource($assignment), message: 'Internship assignment marked as completed.');
    }

    public function cancel(CancelInternshipAssignmentRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);

        try {
            $assignment = $this->service->cancel($assignment, $request->user(), $request->validated('reason'));
        } catch (InternshipAssignmentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $this->loadDetail($assignment);

        return $this->success(data: new InternshipAssignmentResource($assignment), message: 'Internship assignment cancelled successfully.');
    }

    private function loadDetail(InternshipAssignment $assignment): void
    {
        $assignment->load([
            ...self::EAGER_LOAD_DETAIL,
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
            'facultyHistory' => fn ($q) => $q->orderBy('created_at')->with(['fromFaculty.user', 'toFaculty.user', 'changedBy']),
        ]);
    }
}
