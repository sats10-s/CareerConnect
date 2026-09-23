<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InterviewException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelInterviewRequest;
use App\Http\Requests\Admin\CompleteInterviewRequest;
use App\Http\Requests\Admin\RescheduleInterviewRequest;
use App\Http\Requests\Admin\StoreInterviewRequest;
use App\Http\Requests\Admin\UpdateInterviewRequest;
use App\Http\Resources\AdminInterviewListResource;
use App\Http\Resources\AdminInterviewResource;
use App\Models\Application;
use App\Models\Interview;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Services\InterviewService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin management of interviews. Route-gated by
 * auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN (see routes/api.php);
 * InterviewPolicy adds the object-level authorization layer on top of
 * that route gate, same pattern as Admin\ApplicationController. Every
 * lifecycle write goes through App\Services\InterviewService, never a
 * direct ->update() here.
 */
class InterviewController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD_DETAIL = [
        'application.student.department',
        'application.student.batch',
        'application.student.user',
        'application.applicable.company',
        'creator',
        'updater',
    ];

    public function __construct(
        private readonly InterviewService $interviewService,
    ) {}

    public function store(StoreInterviewRequest $request, Application $application): JsonResponse
    {
        Gate::authorize('create', Interview::class);

        try {
            $interview = $this->interviewService->create($application, $request->validated(), $request->user());
        } catch (InterviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $interview->load([...self::EAGER_LOAD_DETAIL, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminInterviewResource($interview), message: 'Interview scheduled successfully.', status: 201);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Interview::class);

        $query = Interview::query()->with(['application.student.user', 'application.student.department', 'application.applicable.company']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($mode = $request->input('mode')) {
            $query->where('mode', $mode);
        }

        if ($round = (int) $request->input('round')) {
            $query->where('round', $round);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('scheduled_at', '>=', Carbon::parse($dateFrom));
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('scheduled_at', '<=', Carbon::parse($dateTo));
        }

        if ($applicableClass = $this->applicableClassFor($request->input('type'))) {
            $query->whereHas('application', fn ($q) => $q->where('applicable_type', $applicableClass));
        }

        if ($companyId = (int) $request->input('company_id')) {
            $query->whereHas('application', fn ($q) => $q->whereHasMorph(
                'applicable',
                [JobPosting::class, Internship::class],
                fn ($inner) => $inner->where('company_id', $companyId)
            ));
        }

        if ($studentId = (int) $request->input('student_id')) {
            $query->whereHas('application', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($applicationStatus = $request->input('application_status')) {
            $query->whereHas('application', fn ($q) => $q->where('status', $applicationStatus));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $interviews = $query->latest('scheduled_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => AdminInterviewListResource::collection($interviews->items()),
                'meta' => [
                    'current_page' => $interviews->currentPage(),
                    'per_page' => $interviews->perPage(),
                    'total' => $interviews->total(),
                    'last_page' => $interviews->lastPage(),
                ],
            ],
            message: 'Interviews retrieved successfully.',
        );
    }

    public function show(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        $interview->load([...self::EAGER_LOAD_DETAIL, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminInterviewResource($interview), message: 'Interview retrieved successfully.');
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        try {
            $interview = $this->interviewService->update($interview, $request->validated(), $request->user());
        } catch (InterviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $interview->load([...self::EAGER_LOAD_DETAIL, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminInterviewResource($interview), message: 'Interview updated successfully.');
    }

    public function reschedule(RescheduleInterviewRequest $request, Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        try {
            $interview = $this->interviewService->reschedule($interview, $request->validated(), $request->user());
        } catch (InterviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $interview->load([...self::EAGER_LOAD_DETAIL, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminInterviewResource($interview), message: 'Interview rescheduled successfully.');
    }

    public function complete(CompleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        try {
            $interview = $this->interviewService->complete($interview, $request->user(), $request->validated('remarks'));
        } catch (InterviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $interview->load([...self::EAGER_LOAD_DETAIL, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminInterviewResource($interview), message: 'Interview marked as completed.');
    }

    public function cancel(CancelInterviewRequest $request, Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        try {
            $interview = $this->interviewService->cancel($interview, $request->user(), $request->validated('reason'));
        } catch (InterviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $interview->load([...self::EAGER_LOAD_DETAIL, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminInterviewResource($interview), message: 'Interview cancelled successfully.');
    }

    private function applicableClassFor(?string $type): ?string
    {
        return match ($type) {
            'job' => JobPosting::class,
            'internship' => Internship::class,
            default => null,
        };
    }
}
