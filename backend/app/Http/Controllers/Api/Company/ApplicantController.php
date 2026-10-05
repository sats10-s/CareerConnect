<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateApplicationStatusRequest;
use App\Http\Resources\CompanyApplicantResource;
use App\Models\Application;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Services\ApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/company/applications - applicants to the recruiter's own company's
 * postings only, in the privacy-safe CompanyApplicantResource shape, plus
 * their resume file.
 * Status changes go through the same ApplicationService (and therefore the
 * same transition rules, history rows and student notifications) the
 * placement office uses.
 */
class ApplicantController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD_DETAIL = [
        'student.user',
        'student.department',
        'student.batch',
        'student.skills',
        'student.projects',
        'student.certifications',
        'applicable.company',
    ];

    public function __construct(private readonly ApplicationService $applications) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(['job', 'internship'])],
            'posting_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(ApplicationStatus::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = $this->ownApplications($request)->with(['student.user', 'student.department', 'student.batch', 'applicable.company'])->withCount('interviews');

        if ($type = $filters['type'] ?? null) {
            $query->where('applicable_type', $type === 'job' ? JobPosting::class : Internship::class);
            if ($postingId = $filters['posting_id'] ?? null) {
                $query->where('applicable_id', $postingId);
            }
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->whereHas('student', fn ($s) => $s->where('roll_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")));
        }

        $page = $query->latest('applied_at')->paginate((int) ($filters['per_page'] ?? 15));

        return $this->success(data: [
            'items' => CompanyApplicantResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $application): JsonResponse
    {
        $model = $this->find($request, $application);

        return $this->success(data: new CompanyApplicantResource($this->loadDetail($model)));
    }

    public function updateStatus(UpdateApplicationStatusRequest $request, int $application): JsonResponse
    {
        $model = $this->find($request, $application);

        try {
            $model = $this->applications->updateStatus($model, ApplicationStatus::from($request->validated('status')), $request->user(), $request->validated('remarks'));
        } catch (ApplicationException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(data: new CompanyApplicantResource($this->loadDetail($model)), message: 'Application status updated.');
    }

    /**
     * GET /api/company/applications/{application}/resume - the applicant's
     * resume, for an application to the caller's own company's posting only.
     * The resume file itself may carry the student's own contact details;
     * sharing it with recruiters of the postings they applied to was an
     * explicit product decision.
     */
    public function resume(Request $request, int $application): StreamedResponse|JsonResponse
    {
        $student = $this->find($request, $application)->student;

        if (! $student?->resume_path || ! Storage::disk('local')->exists($student->resume_path)) {
            return $this->failure('This student has not uploaded a resume.', [], 404);
        }

        return Storage::disk('local')->download($student->resume_path, $student->resume_original_name ?? 'resume');
    }

    /** Applications whose job or internship belongs to the caller's company. */
    private function ownApplications(Request $request): Builder
    {
        $companyId = $request->user()->company_id;

        return Application::query()->whereHasMorph('applicable', [JobPosting::class, Internship::class], fn ($q) => $q->where('company_id', $companyId));
    }

    private function find(Request $request, int $id): Application
    {
        $application = $this->ownApplications($request)->findOrFail($id);
        Gate::authorize('manageAsCompany', $application);

        return $application;
    }

    private function loadDetail(Application $application): Application
    {
        return $application->load([
            ...self::EAGER_LOAD_DETAIL,
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
        ])->loadCount('interviews');
    }
}
