<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Enums\CompanyStatus;
use App\Exceptions\CompanyReviewException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ReviewCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Super Admin > Company Approvals (route-gated by role:SUPER_ADMIN and
 * CompanyPolicy::review). Placement Admins can see company status on their
 * Companies page but cannot change it.
 */
class CompanyApprovalController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly CompanyRegistrationService $registration) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('review', Company::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(CompanyStatus::values())],
            'source' => ['sometimes', 'nullable', Rule::in(['ADMIN', 'SELF_REGISTERED'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = Company::query()->with(['owner', 'reviewer']);
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($source = $filters['source'] ?? null) {
            $query->where('source', $source);
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhere('website', 'like', "%{$search}%");
            });
        }

        // Pending first (oldest waiting at the top), then most recently created.
        $companies = $query
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN created_at END ASC")
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $pool = Company::query()->get(['id', 'name', 'website', 'status']);
        $items = collect($companies->items())->map(fn (Company $company) => array_merge(
            (new CompanyResource($company))->resolve($request),
            ['possible_duplicates' => $this->registration->duplicatesFor($company, $pool)],
        ));

        $counts = Company::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->success(data: [
            'items' => $items,
            'meta' => [
                'current_page' => $companies->currentPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'last_page' => $companies->lastPage(),
            ],
            'counts' => collect(CompanyStatus::values())->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        Gate::authorize('review', $company);

        return $this->success(data: $this->present($request, $company));
    }

    public function approve(ReviewCompanyRequest $request, Company $company): JsonResponse
    {
        return $this->decide($request, $company, fn () => $this->registration->approve($company, $request->user(), $request->validated('note')), 'Company approved.');
    }

    public function reject(ReviewCompanyRequest $request, Company $company): JsonResponse
    {
        return $this->decide($request, $company, fn () => $this->registration->reject($company, $request->user(), $request->validated('reason')), 'Company rejected.');
    }

    public function suspend(ReviewCompanyRequest $request, Company $company): JsonResponse
    {
        return $this->decide($request, $company, fn () => $this->registration->suspend($company, $request->user(), $request->validated('reason')), 'Company suspended and its recruiters signed out.');
    }

    public function reactivate(ReviewCompanyRequest $request, Company $company): JsonResponse
    {
        return $this->decide($request, $company, fn () => $this->registration->reactivate($company, $request->user(), $request->validated('note')), 'Company reactivated.');
    }

    private function decide(Request $request, Company $company, callable $action, string $message): JsonResponse
    {
        Gate::authorize('review', $company);

        try {
            $action();
        } catch (CompanyReviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(data: $this->present($request, $company->refresh()), message: $message);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, Company $company): array
    {
        $company->load(['owner', 'reviewer'])->loadCount(['jobPostings', 'internships']);

        return array_merge((new CompanyResource($company))->resolve($request), [
            'possible_duplicates' => $this->registration->duplicatesFor($company),
        ]);
    }
}
