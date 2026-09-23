<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\OpportunityStatus;
use App\Exceptions\ApplicationException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\InternshipResource;
use App\Http\Resources\JobPostingResource;
use App\Http\Resources\OpportunityListResource;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Services\ApplicationService;
use App\Services\OpportunityEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The published-opportunities listing a student browses, plus the
 * single-opportunity detail views. Route-gated by auth:sanctum +
 * role:STUDENT (see routes/api.php).
 *
 * index(): backed by TWO separate tables (job_postings/internships)
 * with no shared base table, so a single query can't paginate across
 * both directly. Approach: build a lightweight UNION ALL of just
 * (id, opportunity_type, sort columns) for whichever rows match the
 * filters/search across both tables - via ->toBase() so the union
 * returns plain rows rather than being (mis)hydrated as one Eloquent
 * model class - paginate THAT, then hydrate the current page's ids
 * back into real JobPosting/Internship models (with eager loads) in
 * two "WHERE id IN (...)" queries instead of loading every matching
 * row from both tables up front.
 *
 * PRODUCT RULE - do not weaken this: index() lists every PUBLISHED
 * opportunity to every student, full stop. It never filters, sorts, or
 * hides anything based on eligibility - the admin decides eligibility
 * criteria, this app never decides who "should" see an opportunity.
 * Eligibility only appears in showJob()/showInternship() below, as
 * informational status about ONE opportunity the student already
 * found, never as a listing filter.
 *
 * showJob()/showInternship(): the detail payload merges in a sibling
 * `my_eligibility` key - the authenticated student's own evaluation
 * against that opportunity's admin-configured criteria (see
 * OpportunityEligibilityService) - alongside the resource's own
 * `eligibility` block (the configured criteria themselves, same as
 * what an admin sees). The student is always derived from
 * $request->user()->student, never trusted from the URL/request.
 *
 * applyToJob()/applyToInternship() (Application Lifecycle increment):
 * live here rather than on Api\Student\ApplicationController because
 * they need the same Gate::authorize('view', $opportunity) resolution
 * showJob()/showInternship() already do - a student gets the same 403
 * for trying to apply to a draft/closed/archived opportunity as they
 * would for viewing one. All the actual business rules (published-only,
 * no duplicates, eligibility enforcement) live in
 * App\Services\ApplicationService, not here.
 */
class OpportunityController extends Controller
{
    use FormatsApiResponse;

    public function __construct(
        private readonly OpportunityEligibilityService $eligibilityService,
        private readonly ApplicationService $applicationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', '')) ?: null;
        $type = $request->input('type') ?: null; // 'job' | 'internship'
        $location = trim((string) $request->input('location', '')) ?: null;
        $workMode = $request->input('work_mode') ?: null;
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $page = max((int) $request->input('page', 1), 1);

        $jobRows = $this->filteredBaseQuery(
            JobPosting::query()->toBase(),
            'job',
            $search,
            $location,
            $workMode,
        );

        $internshipRows = $this->filteredBaseQuery(
            Internship::query()->toBase(),
            'internship',
            $search,
            $location,
            $workMode,
        );

        $union = match ($type) {
            'job' => $jobRows,
            'internship' => $internshipRows,
            default => $jobRows->unionAll($internshipRows),
        };

        $paginator = $union->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items());
        $jobIds = $rows->where('opportunity_type', 'job')->pluck('id')->all();
        $internshipIds = $rows->where('opportunity_type', 'internship')->pluck('id')->all();

        $jobs = $jobIds ? JobPosting::with('company')->whereIn('id', $jobIds)->get()->keyBy('id') : collect();
        $internships = $internshipIds ? Internship::with('company')->whereIn('id', $internshipIds)->get()->keyBy('id') : collect();

        $hydrated = $rows
            ->map(fn ($row) => $row->opportunity_type === 'job' ? $jobs->get($row->id) : $internships->get($row->id))
            ->filter()
            ->values();

        return $this->success(
            data: [
                'items' => OpportunityListResource::collection($hydrated),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            message: 'Opportunities retrieved successfully.',
        );
    }

    public function showJob(Request $request, JobPosting $jobPosting): JsonResponse
    {
        Gate::authorize('view', $jobPosting);

        $jobPosting->load([
            'company',
            'eligibilityCriteria',
            'eligibleDepartments.department',
            'eligibleBatches.batch',
            'eligibleYears',
            'requiredSkills',
        ]);

        $data = (new JobPostingResource($jobPosting))->resolve();
        $data['my_eligibility'] = $this->eligibilityService->evaluate($request->user()->student, $jobPosting);

        return $this->success(data: $data, message: 'Job posting retrieved successfully.');
    }

    public function showInternship(Request $request, Internship $internship): JsonResponse
    {
        Gate::authorize('view', $internship);

        $internship->load([
            'company',
            'eligibilityCriteria',
            'eligibleDepartments.department',
            'eligibleBatches.batch',
            'eligibleYears',
            'requiredSkills',
        ]);

        $data = (new InternshipResource($internship))->resolve();
        $data['my_eligibility'] = $this->eligibilityService->evaluate($request->user()->student, $internship);

        return $this->success(data: $data, message: 'Internship retrieved successfully.');
    }

    public function applyToJob(Request $request, JobPosting $jobPosting): JsonResponse
    {
        Gate::authorize('view', $jobPosting);

        return $this->apply($request, $jobPosting);
    }

    public function applyToInternship(Request $request, Internship $internship): JsonResponse
    {
        Gate::authorize('view', $internship);

        return $this->apply($request, $internship);
    }

    private function apply(Request $request, JobPosting|Internship $opportunity): JsonResponse
    {
        $student = $request->user()->student;

        if (! $student) {
            return $this->failure('Your student profile could not be found.', status: 422);
        }

        try {
            $application = $this->applicationService->apply($student, $opportunity, $request->user());
        } catch (ApplicationException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $application->load(['applicable.company', 'statusHistory' => fn ($q) => $q->orderBy('created_at')]);

        return $this->success(
            data: new ApplicationResource($application),
            message: 'Application submitted successfully.',
            status: 201,
        );
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     */
    private function filteredBaseQuery($query, string $type, ?string $search, ?string $location, ?string $workMode)
    {
        $query
            ->select('id', DB::raw("'{$type}' as opportunity_type"), 'created_at')
            ->where('status', OpportunityStatus::PUBLISHED->value);

        if ($search) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($location) {
            $query->where('location', 'like', "%{$location}%");
        }

        if ($workMode) {
            $query->where('work_mode', $workMode);
        }

        return $query;
    }
}
