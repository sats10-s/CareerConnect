<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\BuildsEligibilityPayload;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreJobPostingRequest;
use App\Http\Requests\Admin\UpdateJobPostingRequest;
use App\Http\Resources\JobPostingResource;
use App\Models\JobPosting;
use App\Services\CompanyPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin management of job postings. Route-gated by
 * auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN (see routes/api.php);
 * JobPostingPolicy adds the object-level authorization layer on top of
 * that route gate. That role gate (nobody but PLACEMENT_ADMIN/
 * SUPER_ADMIN can reach these actions at all) is also what satisfies
 * "only admins may create or modify eligibility criteria" - eligibility
 * is configured through this same create/update contract, not a
 * separate endpoint (see BuildsEligibilityPayload).
 */
class JobPostingController extends Controller
{
    use BuildsEligibilityPayload;
    use FormatsApiResponse;

    private const ELIGIBILITY_FIELDS = [
        'eligibility_enforced',
        'minimum_cgpa',
        'maximum_backlogs',
        'eligible_department_ids',
        'eligible_batch_ids',
        'eligible_years',
        'required_skills',
    ];

    private const EAGER_LOAD = [
        'company',
        'creator',
        'updater',
        'eligibilityCriteria',
        'eligibleDepartments.department',
        'eligibleBatches.batch',
        'eligibleYears',
        'requiredSkills',
    ];

    public function __construct(private readonly CompanyPostingService $companyPostings) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', JobPosting::class);

        $query = JobPosting::query()->with(['company', 'creator']);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // ADMIN | COMPANY, and the queue of company drafts waiting to be published.
        if (in_array($source = $request->input('source'), ['ADMIN', 'COMPANY'], true)) {
            $query->where('source', $source);
        }

        if ($request->boolean('awaiting_review')) {
            $query->where('source', 'COMPANY')->where('status', 'draft')->whereNotNull('submitted_for_review_at');
        }

        if ($companyId = (int) $request->input('company_id')) {
            $query->where('company_id', $companyId);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $jobPostings = $query->latest()->paginate($perPage);

        return $this->success(
            data: [
                'items' => JobPostingResource::collection($jobPostings->items()),
                'meta' => [
                    'current_page' => $jobPostings->currentPage(),
                    'per_page' => $jobPostings->perPage(),
                    'total' => $jobPostings->total(),
                    'last_page' => $jobPostings->lastPage(),
                ],
            ],
            message: 'Job postings retrieved successfully.',
        );
    }

    public function store(StoreJobPostingRequest $request): JsonResponse
    {
        Gate::authorize('create', JobPosting::class);

        $jobPosting = DB::transaction(function () use ($request) {
            $data = $request->safe()->except(self::ELIGIBILITY_FIELDS);
            $data['posted_by'] = $request->user()->id;

            $jobPosting = JobPosting::create($data);
            $jobPosting->syncEligibility($this->eligibilityPayloadFromRequest($request));

            return $jobPosting;
        });

        $jobPosting->load(self::EAGER_LOAD);

        return $this->success(
            data: new JobPostingResource($jobPosting),
            message: 'Job posting created successfully.',
            status: 201,
        );
    }

    public function show(JobPosting $jobPosting): JsonResponse
    {
        Gate::authorize('view', $jobPosting);

        $jobPosting->load(self::EAGER_LOAD);

        return $this->success(data: new JobPostingResource($jobPosting), message: 'Job posting retrieved successfully.');
    }

    public function update(UpdateJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        Gate::authorize('update', $jobPosting);

        $previousStatus = $jobPosting->status;

        DB::transaction(function () use ($request, $jobPosting) {
            $data = $request->safe()->except(self::ELIGIBILITY_FIELDS);
            $data['updated_by'] = $request->user()->id;

            $jobPosting->update($data);
            $jobPosting->syncEligibility($this->eligibilityPayloadFromRequest($request));
        });

        $jobPosting->refresh()->load(self::EAGER_LOAD);
        $this->companyPostings->afterAdminUpdate($jobPosting, $previousStatus);

        return $this->success(data: new JobPostingResource($jobPosting), message: 'Job posting updated successfully.');
    }
}
