<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Enums\InternshipDocumentStatus;
use App\Exceptions\InternshipDocumentException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternshipDocument\InternshipDocumentFilterRequest;
use App\Http\Requests\InternshipDocument\ReviewInternshipDocumentRequest;
use App\Http\Resources\InternshipDocumentListResource;
use App\Http\Resources\InternshipDocumentResource;
use App\Models\InternshipDocument;
use App\Services\InternshipDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A faculty member's own document-review workflow. PRODUCT RULE - do
 * not weaken this: index() is unconditionally scoped to
 * `internshipAssignment.faculty_id = the authenticated faculty's OWN
 * Faculty record id` (never the User id - see
 * InternshipDocumentPolicy's docblock), so a faculty member can never
 * see or review documents for another faculty's students - only what
 * is explicitly assigned to them. Even when a student_id filter is
 * supplied, it is applied as an ADDITIONAL constraint on top of that
 * base scope, never in place of it, so it can never be used to reach
 * another faculty's student.
 *
 * There is deliberately no store()/resubmit() action here at all - a
 * faculty member never uploads or replaces a student's file, only
 * moves it through the review lifecycle via updateStatus() (see
 * InternshipDocumentPolicy's docblock and
 * App\Services\InternshipDocumentService::startReview()/approve()/
 * requestChanges(), which never touch the document's own file/
 * student_notes).
 */
class InternshipDocumentController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.student.department',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship.company',
        'uploadedBy',
    ];

    public function __construct(private readonly InternshipDocumentService $documentService) {}

    public function index(InternshipDocumentFilterRequest $request): JsonResponse
    {
        $faculty = $request->user()->faculty;

        if ($faculty === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Documents retrieved successfully.',
            );
        }

        $query = InternshipDocument::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $faculty->id))
            ->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            // ANDed onto the faculty-scoped base query above - a
            // student_id belonging to another faculty's assignment
            // simply matches zero rows here, it can never widen the
            // scope (see this class's own docblock).
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($documentType = $request->validated('document_type')) {
            $query->where('document_type', $documentType);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($fromDate = $request->validated('from_date')) {
            $query->where('submitted_at', '>=', $fromDate);
        }

        if ($toDate = $request->validated('to_date')) {
            $query->where('submitted_at', '<=', $toDate);
        }

        if ($search = trim((string) $request->validated('search', ''))) {
            $query->whereHas('internshipAssignment', function ($q) use ($search) {
                $q->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                });
            });
        }

        $perPage = min(max((int) $request->validated('per_page', 15), 1), 100);
        $records = $query->orderByDesc('submitted_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => InternshipDocumentListResource::collection($records->items()),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
            message: 'Documents retrieved successfully.',
        );
    }

    public function show(InternshipDocument $document): JsonResponse
    {
        Gate::authorize('view', $document);

        $document->load([...self::EAGER_LOAD, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new InternshipDocumentResource($document), message: 'Document retrieved successfully.');
    }

    public function download(InternshipDocument $document): StreamedResponse|JsonResponse
    {
        Gate::authorize('download', $document);

        try {
            return $this->documentService->retrieveForDownload($document);
        } catch (InternshipDocumentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }
    }

    public function updateStatus(ReviewInternshipDocumentRequest $request, InternshipDocument $document): JsonResponse
    {
        Gate::authorize('review', $document);

        $status = InternshipDocumentStatus::from($request->validated('status'));
        $remarks = $request->validated('faculty_review_remarks');

        try {
            $document = match ($status) {
                InternshipDocumentStatus::UNDER_REVIEW => $this->documentService->startReview($document, $request->user()),
                InternshipDocumentStatus::APPROVED => $this->documentService->approve($document, $request->user(), $remarks),
                InternshipDocumentStatus::CHANGES_REQUESTED => $this->documentService->requestChanges($document, $request->user(), (string) $remarks),
                default => throw new InternshipDocumentException('Unsupported review status.'),
            };
        } catch (InternshipDocumentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $document->load([...self::EAGER_LOAD, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new InternshipDocumentResource($document), message: 'Document review updated successfully.');
    }
}
