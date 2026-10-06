<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateApplicationStatusRequest;
use App\Http\Resources\AdminApplicationListResource;
use App\Http\Resources\AdminApplicationResource;
use App\Models\Application;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin management of applications. Route-gated by
 * auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN (see routes/api.php);
 * ApplicationPolicy adds the object-level authorization layer on top
 * of that route gate, same pattern as Admin\JobPostingController /
 * Admin\InternshipController. Status changes always go through
 * App\Services\ApplicationService::updateStatus(), never a direct
 * ->update() here - see that class for the transition-validation and
 * history-writing logic.
 */
class ApplicationController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD_DETAIL = [
        'student.department',
        'student.batch',
        'student.user',
        'applicable.company',
    ];

    public function __construct(
        private readonly ApplicationService $applicationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Application::class);

        // Eligibility relations too: each open application is re-checked
        // against the student's current CGPA/backlogs for the "no longer
        // meets criteria" badge (see AdminApplicationListResource).
        $query = Application::query()->with([
            'student.user', 'student.department', 'student.skills', 'applicable.company',
            'applicable.eligibilityCriteria', 'applicable.eligibleDepartments', 'applicable.eligibleBatches',
            'applicable.eligibleYears', 'applicable.requiredSkills',
        ]);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($applicableClass = $this->applicableClassFor($request->input('type'))) {
            $query->where('applicable_type', $applicableClass);
        }

        if ($studentId = (int) $request->input('student_id')) {
            $query->where('student_id', $studentId);
        }

        if ($companyId = (int) $request->input('company_id')) {
            $query->whereHasMorph(
                'applicable',
                [JobPosting::class, Internship::class],
                fn ($inner) => $inner->where('company_id', $companyId)
            );
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($outer) use ($search) {
                $outer->whereHasMorph(
                    'applicable',
                    [JobPosting::class, Internship::class],
                    fn ($inner) => $inner->where('title', 'like', "%{$search}%")
                )->orWhereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $applications = $query->latest('applied_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => AdminApplicationListResource::collection($applications->items()),
                'meta' => [
                    'current_page' => $applications->currentPage(),
                    'per_page' => $applications->perPage(),
                    'total' => $applications->total(),
                    'last_page' => $applications->lastPage(),
                ],
            ],
            message: 'Applications retrieved successfully.',
        );
    }

    public function show(Application $application): JsonResponse
    {
        Gate::authorize('view', $application);

        $application->load([
            ...self::EAGER_LOAD_DETAIL,
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
        ]);

        return $this->success(data: new AdminApplicationResource($application), message: 'Application retrieved successfully.');
    }

    public function updateStatus(UpdateApplicationStatusRequest $request, Application $application): JsonResponse
    {
        Gate::authorize('updateStatus', $application);

        $target = ApplicationStatus::from($request->validated('status'));

        try {
            $application = $this->applicationService->updateStatus(
                $application,
                $target,
                $request->user(),
                $request->validated('remarks'),
            );
        } catch (ApplicationException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $application->load([
            ...self::EAGER_LOAD_DETAIL,
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
        ]);

        return $this->success(data: new AdminApplicationResource($application), message: 'Application status updated successfully.');
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
