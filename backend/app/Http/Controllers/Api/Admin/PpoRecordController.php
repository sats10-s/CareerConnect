<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppo\PpoRecordFilterRequest;
use App\Http\Resources\AdminPpoRecordResource;
use App\Http\Resources\PpoRecordListResource;
use App\Models\PpoRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Placement-admin visibility into PPO records across the system.
 * Route-gated by auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN (see
 * routes/api.php); PpoRecordPolicy adds the object-level authorization
 * layer on top of that route gate. Mirrors
 * Admin\InternshipCompletionController exactly - read-only, no create/
 * update action at all, matching the module spec's own Admin API list
 * (`GET /api/admin/ppo-records` only).
 */
class PpoRecordController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.student.department',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship.company',
        'company',
    ];

    public function index(PpoRecordFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', PpoRecord::class);

        $query = PpoRecord::query()->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
        }

        if ($facultyId = (int) $request->validated('faculty_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $facultyId));
        }

        if ($companyId = (int) $request->validated('company_id')) {
            $query->where('company_id', $companyId);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($fromDate = $request->validated('from_date')) {
            $query->where('offered_at', '>=', $fromDate);
        }

        if ($toDate = $request->validated('to_date')) {
            $query->where('offered_at', '<=', $toDate);
        }

        if ($search = trim((string) $request->validated('search', ''))) {
            // The whole OR-of-matches is wrapped in a single top-level
            // where(function...) so it combines with AND against the
            // other filters above, rather than an orWhereHas() at the
            // top level OR-ing against the ENTIRE preceding query (see
            // this controller's own regression coverage for why this
            // grouping matters). `company` is queried as a DIRECT
            // relation here (unlike InternshipDocument/
            // InternshipCompletion, which only ever reach it by nesting
            // through internshipAssignment.internship.company) so it
            // cannot be nested inside the internshipAssignment closure
            // the way student/faculty are.
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('internshipAssignment', function ($q) use ($search) {
                    $q->whereHas('student', function ($studentQuery) use ($search) {
                        $studentQuery->where('roll_number', 'like', "%{$search}%")
                            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                    })
                        ->orWhereHas('faculty.user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                })
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->validated('per_page', 15), 1), 100);
        $records = $query->orderByDesc('offered_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => PpoRecordListResource::collection($records->items()),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
            message: 'PPO records retrieved successfully.',
        );
    }

    public function show(PpoRecord $ppoRecord): JsonResponse
    {
        Gate::authorize('view', $ppoRecord);

        $ppoRecord->load([...self::EAGER_LOAD, 'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy')]);

        return $this->success(data: new AdminPpoRecordResource($ppoRecord), message: 'PPO record retrieved successfully.');
    }
}
