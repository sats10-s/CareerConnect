<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\OpportunityStatus;
use App\Exceptions\CompanyReviewException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanyPostingRequest;
use App\Http\Resources\InternshipResource;
use App\Http\Resources\JobPostingResource;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Services\CompanyPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/company/postings - a recruiter's own jobs and internships (behind
 * role:COMPANY + company.approved). Every lookup is scoped to the caller's
 * company, so another company's posting id is a 404, never a leak.
 */
class PostingController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = ['company', 'creator', 'eligibilityCriteria', 'eligibleDepartments.department', 'eligibleBatches.batch', 'eligibleYears', 'requiredSkills'];

    public function __construct(private readonly CompanyPostingService $postings) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(['job', 'internship'])],
            'status' => ['sometimes', 'nullable', Rule::in(OpportunityStatus::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $companyId = $request->user()->company_id;

        $items = collect(CompanyPostingService::TYPES)
            ->filter(fn ($class, $type) => empty($filters['type']) || $filters['type'] === $type)
            ->flatMap(function ($class) use ($companyId, $filters) {
                $query = $class::query()->where('company_id', $companyId)->withCount('applications')->with('company');
                if ($status = $filters['status'] ?? null) {
                    $query->where('status', $status);
                }
                if ($search = trim((string) ($filters['search'] ?? ''))) {
                    $query->where('title', 'like', "%{$search}%");
                }

                return $query->get();
            })
            ->sortByDesc('created_at')
            ->values()
            ->map(fn ($posting) => $this->present($posting));

        return $this->success(data: ['items' => $items]);
    }

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $posting = $this->find($request, $type, $id);
        $posting->load(self::EAGER_LOAD)->loadCount('applications');

        return $this->success(data: $this->present($posting));
    }

    public function store(CompanyPostingRequest $request): JsonResponse
    {
        $type = $request->type();
        $posting = DB::transaction(fn () => $this->postings->create($type, $request->user()->company, $request->user(), $request->postingData()));
        $posting->load(self::EAGER_LOAD)->loadCount('applications');

        return $this->success(data: $this->present($posting), message: 'Draft saved. Submit it when it is ready.', status: 201);
    }

    public function update(CompanyPostingRequest $request, string $type, int $id): JsonResponse
    {
        $posting = $this->find($request, $type, $id);

        return $this->attempt(fn () => $this->postings->update($posting, $request->user(), $request->postingData()), 'Posting updated.');
    }

    public function submit(Request $request, string $type, int $id): JsonResponse
    {
        $posting = $this->find($request, $type, $id);

        try {
            $result = $this->postings->submit($posting, $request->user());
        } catch (CompanyReviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $posting = $result['posting']->load(self::EAGER_LOAD)->loadCount('applications');

        return $this->success(
            data: $this->present($posting),
            message: $result['published'] ? 'Posting published.' : 'Submitted. The placement office will review and publish it.',
        );
    }

    public function close(Request $request, string $type, int $id): JsonResponse
    {
        $posting = $this->find($request, $type, $id);

        return $this->attempt(fn () => $this->postings->close($posting, $request->user()), 'Posting closed to new applications.');
    }

    private function attempt(callable $action, string $message): JsonResponse
    {
        try {
            $posting = $action();
        } catch (CompanyReviewException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $posting->refresh()->load(self::EAGER_LOAD)->loadCount('applications');

        return $this->success(data: $this->present($posting), message: $message);
    }

    private function find(Request $request, string $type, int $id): JobPosting|Internship
    {
        $class = CompanyPostingService::TYPES[$type] ?? abort(404);
        $posting = $class::query()->where('company_id', $request->user()->company_id)->findOrFail($id);
        Gate::authorize('manageAsCompany', $posting);

        return $posting;
    }

    /** @return array<string, mixed> */
    private function present(JobPosting|Internship $posting): array
    {
        $resource = $posting instanceof Internship ? new InternshipResource($posting) : new JobPostingResource($posting);

        return array_merge($resource->resolve(), [
            'applications_count' => $posting->applications_count ?? 0,
            'review_state' => $this->reviewState($posting),
        ]);
    }

    /** draft | in_review | live | closed | archived - what the recruiter should read the posting's state as. */
    private function reviewState(JobPosting|Internship $posting): string
    {
        return match ($posting->status) {
            OpportunityStatus::DRAFT => $posting->submitted_for_review_at ? 'in_review' : 'draft',
            OpportunityStatus::PUBLISHED => 'live',
            OpportunityStatus::CLOSED => 'closed',
            OpportunityStatus::ARCHIVED => 'archived',
        };
    }
}
