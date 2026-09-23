<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInternshipRequest;
use App\Http\Requests\Admin\UpdateInternshipRequest;
use App\Http\Resources\InternshipResource;
use App\Models\Internship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Mirrors Admin\JobPostingController - see its docblock.
 */
class InternshipController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'company',
        'creator',
        'updater',
        'eligibilityCriteria',
        'eligibleDepartments',
        'eligibleBatches',
        'eligibleYears',
        'requiredSkills',
    ];

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
            $data = $request->safe()->except('eligibility');
            $data['posted_by'] = $request->user()->id;

            $internship = Internship::create($data);

            if ($eligibility = $request->validated('eligibility')) {
                $internship->syncEligibility($eligibility);
            }

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

        DB::transaction(function () use ($request, $internship) {
            $data = $request->safe()->except('eligibility');
            $data['updated_by'] = $request->user()->id;

            $internship->update($data);

            if ($request->has('eligibility')) {
                $internship->syncEligibility($request->validated('eligibility'));
            }
        });

        $internship->refresh()->load(self::EAGER_LOAD);

        return $this->success(data: new InternshipResource($internship), message: 'Internship updated successfully.');
    }
}
