<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\BuildsEligibilityPayload;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInternshipRequest;
use App\Http\Requests\Admin\UpdateInternshipRequest;
use App\Http\Resources\InternshipResource;
use App\Models\Internship;
use App\Services\CompanyPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Mirrors Admin\JobPostingController - see its docblock.
 */
class InternshipController extends Controller
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
        'allow_placed_students',
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
        Gate::authorize('viewAny', Internship::class);

        $query = Internship::query()->with(['company', 'creator']);

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
        $internships = $query->latest()->paginate($perPage);

        return $this->success(
            data: [
                'items' => InternshipResource::collection($internships->items()),
                'meta' => [
                    'current_page' => $internships->currentPage(),
                    'per_page' => $internships->perPage(),
                    'total' => $internships->total(),
                    'last_page' => $internships->lastPage(),
                ],
            ],
            message: 'Internships retrieved successfully.',
        );
    }

    public function store(StoreInternshipRequest $request): JsonResponse
    {
        Gate::authorize('create', Internship::class);

        $internship = DB::transaction(function () use ($request) {
            $data = $request->safe()->except(self::ELIGIBILITY_FIELDS);
            $data['posted_by'] = $request->user()->id;

            $internship = Internship::create($data);
            $internship->syncEligibility($this->eligibilityPayloadFromRequest($request));
            $this->syncPlacedStudentPolicy($request, $internship);

            return $internship;
        });

        $internship->load(self::EAGER_LOAD);

        return $this->success(
            data: new InternshipResource($internship),
            message: 'Internship created successfully.',
            status: 201,
        );
    }

    public function show(Internship $internship): JsonResponse
    {
        Gate::authorize('view', $internship);

        $internship->load(self::EAGER_LOAD);

        return $this->success(data: new InternshipResource($internship), message: 'Internship retrieved successfully.');
    }

    public function update(UpdateInternshipRequest $request, Internship $internship): JsonResponse
    {
        Gate::authorize('update', $internship);

        $previousStatus = $internship->status;

        DB::transaction(function () use ($request, $internship) {
            $data = $request->safe()->except(self::ELIGIBILITY_FIELDS);
            $data['updated_by'] = $request->user()->id;

            $internship->update($data);
            $internship->syncEligibility($this->eligibilityPayloadFromRequest($request));
            $this->syncPlacedStudentPolicy($request, $internship);
        });

        $internship->refresh()->load(self::EAGER_LOAD);
        $this->companyPostings->afterAdminUpdate($internship, $previousStatus);

        return $this->success(data: new InternshipResource($internship), message: 'Internship updated successfully.');
    }
}
