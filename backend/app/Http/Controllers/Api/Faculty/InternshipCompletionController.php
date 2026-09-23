<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Exceptions\InternshipCompletionException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternshipCompletion\FinalizeInternshipCompletionRequest;
use App\Http\Requests\InternshipCompletion\InternshipCompletionFilterRequest;
use App\Http\Requests\InternshipCompletion\StoreInternshipCompletionRequest;
use App\Http\Requests\InternshipCompletion\UpdateInternshipCompletionRequest;
use App\Http\Resources\InternshipCompletionListResource;
use App\Http\Resources\InternshipCompletionResource;
use App\Models\InternshipAssignment;
use App\Models\InternshipCompletion;
use App\Services\InternshipCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A faculty member's own internship-completion workflow. PRODUCT RULE -
 * do not weaken this: index() is unconditionally scoped to
 * `internshipAssignment.faculty_id = the authenticated faculty's OWN
 * Faculty record id` (never the User id - see
 * InternshipCompletionPolicy's docblock), so a faculty member can
 * never see or create/update/finalize a completion for another
 * faculty's students - only what is explicitly assigned to them.
 *
 * store()/update() are both scoped by {assignment} (per the module
 * spec's exact `POST/PATCH /api/faculty/internship-assignments/{assignment}/completion`
 * route), never by a completion's own id - Gate::authorize('create'|'update', ...)
 * is what actually enforces ownership in each case.
 */
class InternshipCompletionController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.student.department',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship.company',
        'completedBy',
        'completionCertificateDocument',
    ];

    public function __construct(private readonly InternshipCompletionService $completionService) {}

    public function index(InternshipCompletionFilterRequest $request): JsonResponse
    {
        $faculty = $request->user()->faculty;

        if ($faculty === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Completion records retrieved successfully.',
            );
        }

        $query = InternshipCompletion::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $faculty->id))
            ->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($outcome = $request->validated('outcome')) {
            $query->where('outcome', $outcome);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($fromDate = $request->validated('from_date')) {
            $query->where('completion_date', '>=', $fromDate);
        }

        if ($toDate = $request->validated('to_date')) {
            $query->where('completion_date', '<=', $toDate);
        }

        if ($search = trim((string) $request->validated('search', ''))) {
            // ANDed onto the faculty-scoped base query above - matches
            // Api\Faculty\InternshipDocumentController's own search
            // block exactly (student-only, since faculty is already
            // scoped to their own students - no faculty-name search
            // needed here).
            $query->whereHas('internshipAssignment', function ($q) use ($search) {
                $q->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                });
            });
        }

        $perPage = min(max((int) $request->validated('per_page', 15), 1), 100);
        $records = $query->orderByDesc('completion_date')->paginate($perPage);

        return $this->success(
            data: [
                'items' => InternshipCompletionListResource::collection($records->items()),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
            message: 'Completion records retrieved successfully.',
        );
    }

    public function show(InternshipCompletion $completion): JsonResponse
    {
        Gate::authorize('view', $completion);

        $completion->load([...self::EAGER_LOAD, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new InternshipCompletionResource($completion), message: 'Completion record retrieved successfully.');
    }

    public function store(StoreInternshipCompletionRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('create', [InternshipCompletion::class, $assignment]);

        try {
            $completion = $this->completionService->createCompletion(
                $assignment,
                $request->user(),
                $request->safe()->only(['completion_date', 'outcome', 'final_remarks', 'completion_certificate_document_id']),
            );
        } catch (InternshipCompletionException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $completion->load(self::EAGER_LOAD);

        return $this->success(data: new InternshipCompletionResource($completion), message: 'Completion record created successfully.', status: 201);
    }

    public function update(UpdateInternshipCompletionRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        $completion = $assignment->completion;

        if ($completion === null) {
            return $this->failure('No completion record exists yet for this assignment. Use POST to create one.', status: 404);
        }

        Gate::authorize('update', $completion);

        try {
            $completion = $this->completionService->updateCompletion(
                $completion,
                $request->user(),
                $request->safe()->only(['completion_date', 'outcome', 'final_remarks', 'completion_certificate_document_id']),
            );
        } catch (InternshipCompletionException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $completion->load(self::EAGER_LOAD);

        return $this->success(data: new InternshipCompletionResource($completion), message: 'Completion record updated successfully.');
    }

    public function finalize(FinalizeInternshipCompletionRequest $request, InternshipCompletion $completion): JsonResponse
    {
        Gate::authorize('finalize', $completion);

        try {
            $completion = $this->completionService->finalizeCompletion(
                $completion,
                $request->user(),
                $request->validated('final_remarks'),
            );
        } catch (InternshipCompletionException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $completion->load([...self::EAGER_LOAD, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new InternshipCompletionResource($completion), message: 'Completion record finalized successfully.');
    }
}
