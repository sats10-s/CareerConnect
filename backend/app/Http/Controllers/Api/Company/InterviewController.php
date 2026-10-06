<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\InterviewStatus;
use App\Exceptions\InterviewException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelInterviewRequest;
use App\Http\Requests\Admin\CompleteInterviewRequest;
use App\Http\Requests\Admin\RescheduleInterviewRequest;
use App\Http\Requests\Admin\StoreInterviewRequest;
use App\Http\Resources\CompanyInterviewResource;
use App\Models\Application;
use App\Models\Interview;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Services\InterviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/company/interviews - interviews for the recruiter's own company's
 * applicants. Scheduling, rescheduling, completing and cancelling all go
 * through InterviewService, exactly like Admin\InterviewController, so the
 * student is notified the same way.
 */
class InterviewController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'application.student.user',
        'application.student.department',
        'application.student.batch',
        'application.applicable.company',
    ];

    public function __construct(private readonly InterviewService $interviews) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(array_map(fn ($c) => $c->value, InterviewStatus::cases()))],
            'upcoming' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = $this->ownInterviews($request)->with(self::EAGER_LOAD);
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($request->boolean('upcoming')) {
            $query->where('scheduled_at', '>=', now())->orderBy('scheduled_at');
        } else {
            $query->latest('scheduled_at');
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 15));

        return $this->success(data: [
            'items' => CompanyInterviewResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $interview): JsonResponse
    {
        return $this->success(data: $this->present($this->find($request, $interview)));
    }

    public function store(StoreInterviewRequest $request, Application $application): JsonResponse
    {
        Gate::authorize('manageAsCompany', $application);

        return $this->attempt(fn () => $this->interviews->create($application, $request->validated(), $request->user()), 'Interview scheduled.', 201);
    }

    public function reschedule(RescheduleInterviewRequest $request, Interview $interview): JsonResponse
    {
        Gate::authorize('manageAsCompany', $interview);

        return $this->attempt(fn () => $this->interviews->reschedule($interview, $request->validated(), $request->user()), 'Interview rescheduled.');
    }

    public function complete(CompleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        Gate::authorize('manageAsCompany', $interview);

        return $this->attempt(fn () => $this->interviews->complete($interview, $request->user(), $request->validated('remarks')), 'Interview marked as completed.');
    }

    public function cancel(CancelInterviewRequest $request, Interview $interview): JsonResponse
    {
        Gate::authorize('manageAsCompany', $interview);

        return $this->attempt(fn () => $this->interviews->cancel($interview, $request->user(), $request->validated('reason')), 'Interview cancelled.');
    }

    private function attempt(callable $action, string $message, int $status = 200): JsonResponse
    {
        try {
            $interview = $action();
        } catch (InterviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(data: $this->present($interview), message: $message, status: $status);
    }

    private function ownInterviews(Request $request): Builder
    {
        $companyId = $request->user()->company_id;

        return Interview::query()->whereHas('application', fn ($a) => $a->whereHasMorph(
            'applicable',
            [JobPosting::class, Internship::class],
            fn ($q) => $q->where('company_id', $companyId),
        ));
    }

    private function find(Request $request, int $id): Interview
    {
        $interview = $this->ownInterviews($request)->findOrFail($id);
        Gate::authorize('manageAsCompany', $interview);

        return $interview;
    }

    private function present(Interview $interview): CompanyInterviewResource
    {
        return new CompanyInterviewResource($interview->load([
            ...self::EAGER_LOAD,
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
        ]));
    }
}
