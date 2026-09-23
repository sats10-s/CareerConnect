<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternshipCompletion\InternshipCompletionFilterRequest;
use App\Http\Resources\AdminInternshipCompletionResource;
use App\Http\Resources\InternshipCompletionListResource;
use App\Models\InternshipCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin visibility into internship completion records across
 * the system. Route-gated by auth:sanctum + role:PLACEMENT_ADMIN,
 * SUPER_ADMIN (see routes/api.php); InternshipCompletionPolicy adds the
 * object-level authorization layer on top of that route gate, same
 * pattern as Admin\InternshipDocumentController.
 *
 * There is deliberately NO admin create/update/finalize action at all -
 * admin gets read-only visibility (index()/show()) and nothing else,
 * matching the module spec's own Admin API list (GET-only) and
 * InternshipDocumentController's "monitoring, not authoring" precedent.
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

    public function index(InternshipCompletionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', InternshipCompletion::class);

        $query = InternshipCompletion::query()->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($facultyId = (int) $request->validated('faculty_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $facultyId));
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

        return $this->success(data: new AdminInternshipCompletionResource($completion), message: 'Completion record retrieved successfully.');
    }
}
