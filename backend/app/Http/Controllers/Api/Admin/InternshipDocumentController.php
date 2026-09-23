<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InternshipDocumentException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternshipDocument\InternshipDocumentFilterRequest;
use App\Http\Resources\AdminInternshipDocumentResource;
use App\Http\Resources\InternshipDocumentListResource;
use App\Models\InternshipDocument;
use App\Services\InternshipDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Placement-admin visibility into internship documents across the
 * system. Route-gated by auth:sanctum + role:PLACEMENT_ADMIN,
 * SUPER_ADMIN (see routes/api.php); InternshipDocumentPolicy adds the
 * object-level authorization layer on top of that route gate, same
 * pattern as Admin\WeeklyProgressReportController.
 *
 * There is deliberately NO admin create/resubmit/review action at all -
 * admin gets read-only visibility (index()/show()/download()) and
 * nothing else. Per the module spec's own "Do NOT give admin an
 * unrestricted ability to alter student-owned documents unless
 * existing CareerConnect architecture explicitly requires it" and
 * "Admins are monitoring/oversight users": no administrative write
 * endpoint is implemented in this increment (see this module's final
 * report for that explicit limitation).
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
        Gate::authorize('viewAny', InternshipDocument::class);

        $query = InternshipDocument::query()->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($facultyId = (int) $request->validated('faculty_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $facultyId));
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
                })
                    ->orWhereHas('faculty.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('internship.company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
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

        return $this->success(data: new AdminInternshipDocumentResource($document), message: 'Document retrieved successfully.');
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
