<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\InternshipDocumentException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternshipDocument\InternshipDocumentFilterRequest;
use App\Http\Requests\InternshipDocument\ResubmitInternshipDocumentRequest;
use App\Http\Requests\InternshipDocument\StoreInternshipDocumentRequest;
use App\Http\Resources\InternshipDocumentListResource;
use App\Http\Resources\InternshipDocumentResource;
use App\Models\InternshipAssignment;
use App\Models\InternshipDocument;
use App\Services\InternshipDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A student's own internship documents - list, single-document detail,
 * uploading a new one, resubmitting after CHANGES_REQUESTED, and an
 * authenticated download. Ownership is always derived by walking
 * document -> internshipAssignment -> student_id (see
 * InternshipDocumentPolicy::studentOwns()) - NEVER trusted from the
 * URL/request, and the authenticated student's own id/record is always
 * used, never accepted as a parameter. There is no
 * review()/updateStatus() action here at all - reviewing is
 * exclusively a faculty action (see
 * Api\Faculty\InternshipDocumentController).
 *
 * There is no {assignment} route segment for store() (see the module
 * spec's exact `POST /api/student/internship-documents` route) -
 * `internship_assignment_id` is a validated field on
 * StoreInternshipDocumentRequest instead, and Gate::authorize('create', ...)
 * below is what actually enforces that it resolves to the caller's OWN
 * assignment; the client can supply any assignment id it likes, but
 * only ever succeeds against one it owns.
 */
class InternshipDocumentController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.student.department',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship.company',
    ];

    public function __construct(private readonly InternshipDocumentService $documentService) {}

    public function index(InternshipDocumentFilterRequest $request): JsonResponse
    {
        $student = $request->user()->student;

        if ($student === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Documents retrieved successfully.',
            );
        }

        $query = InternshipDocument::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $student->id))
            ->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
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

        $document->load(self::EAGER_LOAD);

        return $this->success(data: new InternshipDocumentResource($document), message: 'Document retrieved successfully.');
    }

    public function store(StoreInternshipDocumentRequest $request): JsonResponse
    {
        $assignment = InternshipAssignment::findOrFail($request->validated('internship_assignment_id'));

        Gate::authorize('create', [InternshipDocument::class, $assignment]);

        try {
            $document = $this->documentService->upload(
                $assignment,
                $request->user(),
                $request->file('file'),
                $request->safe()->only(['document_type', 'title', 'student_notes']),
            );
        } catch (InternshipDocumentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $document->load(self::EAGER_LOAD);

        return $this->success(data: new InternshipDocumentResource($document), message: 'Document uploaded successfully.', status: 201);
    }

    public function resubmit(ResubmitInternshipDocumentRequest $request, InternshipDocument $document): JsonResponse
    {
        Gate::authorize('resubmit', $document);

        try {
            $document = $this->documentService->resubmit(
                $document,
                $request->user(),
                $request->file('file'),
                $request->safe()->only(['student_notes']),
            );
        } catch (InternshipDocumentException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $document->load(self::EAGER_LOAD);

        return $this->success(data: new InternshipDocumentResource($document), message: 'Document resubmitted successfully.');
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
}
