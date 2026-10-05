<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Placement Admin > Companies: list, view, add and edit the companies
 * that job and internship postings belong to. There is deliberately no
 * delete - companies are referenced by postings, drives, applications
 * and PPO records, and removing one would orphan that history.
 */
class CompanyController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Company::class);

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(CompanyStatus::values())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = Company::query()->withCount(['jobPostings', 'internships']);

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")->orWhere('industry', 'like', "%{$search}%");
            });
        }

        if ($industry = $validated['industry'] ?? null) {
            $query->where('industry', $industry);
        }

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        $companies = $query->orderBy('name')->paginate((int) ($validated['per_page'] ?? 15));

        return $this->success(data: [
            'items' => CompanyResource::collection($companies->items()),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'last_page' => $companies->lastPage(),
            ],
            // Distinct industries across ALL companies (not just this page), for the filter.
            'industries' => Company::query()->whereNotNull('industry')->distinct()->orderBy('industry')->pluck('industry'),
        ]);
    }

    public function show(Company $company): JsonResponse
    {
        Gate::authorize('view', $company);

        $company->loadCount(['jobPostings', 'internships'])->load(['jobPostings', 'internships']);

        return $this->success(data: new CompanyResource($company));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        Gate::authorize('create', Company::class);

        $company = Company::create($request->validated() + ['is_active' => true]);
        $this->audit->record('company.created', "Added company {$company->name}", $company);
        $company->loadCount(['jobPostings', 'internships']);

        return $this->success(data: new CompanyResource($company), message: 'Company added.', status: 201);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        Gate::authorize('update', $company);

        $company->fill($request->validated());
        $changed = array_keys($company->getDirty());
        $company->save();
        if ($changed) {
            $this->audit->record('company.updated', "Updated company {$company->name}", $company, ['fields' => $changed]);
        }
        $company->loadCount(['jobPostings', 'internships']);

        return $this->success(data: new CompanyResource($company), message: 'Company updated.');
    }
}
