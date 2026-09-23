<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Enums\InternshipAssignmentStatus;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\InternshipAssignmentListResource;
use App\Http\Resources\InternshipAssignmentResource;
use App\Models\InternshipAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A faculty member's own internship assignments. PRODUCT RULE - do
 * not weaken this: index() is unconditionally scoped to
 * `faculty_id = the authenticated faculty's OWN Faculty record id`
 * (never the User id - see App\Policies\InternshipAssignmentPolicy's
 * docblock on why those two ids are not interchangeable), so a
 * faculty member can never see another faculty's assignments, all
 * students, or all internships - only what is explicitly assigned to
 * them (see the module spec's "Faculty access is assignment-based").
 * show() additionally goes through
 * Gate::authorize('view', $assignment) as the IDOR/BOLA guard against
 * a faculty member guessing another assignment's URL id.
 */
class InternshipAssignmentController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD_DETAIL = [
        'student.user', 'student.department', 'student.batch',
        'faculty.user',
        'internship.company',
        'application',
    ];

    public function index(Request $request): JsonResponse
    {
        $faculty = $request->user()->faculty;

        if ($faculty === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Internship assignments retrieved successfully.',
            );
        }

        $query = InternshipAssignment::query()
            ->where('faculty_id', $faculty->id)
            ->with(['student.user', 'student.department', 'faculty.user', 'internship.company']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->input('when') === 'upcoming') {
            $query->where('status', InternshipAssignmentStatus::ASSIGNED);
        } elseif ($request->input('when') === 'current') {
            $query->where('status', InternshipAssignmentStatus::ACTIVE);
        } elseif ($request->input('when') === 'completed') {
            $query->where('status', InternshipAssignmentStatus::COMPLETED);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                })->orWhereHas('internship.company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $assignments = $query->latest('assigned_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => InternshipAssignmentListResource::collection($assignments->items()),
                'meta' => [
                    'current_page' => $assignments->currentPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                    'last_page' => $assignments->lastPage(),
                ],
            ],
            message: 'Internship assignments retrieved successfully.',
        );
    }

    public function show(InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);

        $assignment->load([
            ...self::EAGER_LOAD_DETAIL,
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
            'facultyHistory' => fn ($q) => $q->orderBy('created_at')->with(['fromFaculty.user', 'toFaculty.user', 'changedBy']),
        ]);

        return $this->success(data: new InternshipAssignmentResource($assignment), message: 'Internship assignment retrieved successfully.');
    }
}
