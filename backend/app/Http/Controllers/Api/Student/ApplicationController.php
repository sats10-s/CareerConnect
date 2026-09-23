<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\ApplicationException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\WithdrawApplicationRequest;
use App\Http\Resources\ApplicationListResource;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Services\ApplicationService;
use App\Services\OpportunityEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A student's own applications - list, detail, withdraw. Applying
 * itself lives on Api\Student\OpportunityController (see its
 * docblock) since it needs that controller's opportunity resolution;
 * this controller only ever operates on applications the authenticated
 * student already owns.
 *
 * Every action here derives the acting student from
 * $request->user()->student and, for show()/withdraw(), additionally
 * calls Gate::authorize() against ApplicationPolicy, which checks
 * $application->student_id against that same student - never a
 * client-supplied id. This is what stops a student from reaching
 * another student's application just by changing the {application} id
 * in the URL (IDOR/BOLA - see the module spec's security section).
 */
class ApplicationController extends Controller
{
    use FormatsApiResponse;

    public function __construct(
        private readonly ApplicationService $applicationService,
        private readonly OpportunityEligibilityService $eligibilityService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $student = $request->user()->student;

        if (! $student) {
            return $this->failure('Your student profile could not be found.', status: 422);
        }

        $query = Application::query()
            ->where('student_id', $student->id)
            ->with('applicable.company');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($applicableClass = $this->applicableClassFor($request->input('type'))) {
            $query->where('applicable_type', $applicableClass);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->whereHasMorph(
                'applicable',
                [JobPosting::class, Internship::class],
                fn ($inner) => $inner->where('title', 'like', "%{$search}%")
            );
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $applications = $query->latest('applied_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => ApplicationListResource::collection($applications->items()),
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

    public function show(Request $request, Application $application): JsonResponse
    {
        Gate::authorize('view', $application);

        $application->load([
            'applicable.company',
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
        ]);

        $data = (new ApplicationResource($application))->resolve();
        $data['my_eligibility'] = $this->eligibilityService->evaluate($request->user()->student, $application->applicable);

        return $this->success(data: $data, message: 'Application retrieved successfully.');
    }

    public function withdraw(WithdrawApplicationRequest $request, Application $application): JsonResponse
    {
        Gate::authorize('withdraw', $application);

        try {
            $application = $this->applicationService->withdraw(
                $application,
                $request->user(),
                $request->validated('remarks'),
            );
        } catch (ApplicationException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $application->load([
            'applicable.company',
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
        ]);

        return $this->success(data: new ApplicationResource($application), message: 'Application withdrawn successfully.');
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
