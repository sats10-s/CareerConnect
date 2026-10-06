<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FacultyStudentListResource;
use App\Http\Resources\StudentProfileResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My Students" - GET /api/faculty/students. PRODUCT RULE - do not
 * weaken this: returns ONLY students who have an InternshipAssignment
 * belonging to the AUTHENTICATED faculty's own Faculty record (never
 * the User id) - see App\Models\Faculty::internshipAssignments() and
 * the module spec's explicit "Do not create a separate faculty-student
 * relationship table. The assignment is the source of truth." No
 * separate table backs this endpoint; it is purely a `whereHas`
 * query over the existing internship_assignments table.
 */
class StudentController extends Controller
{
    use FormatsApiResponse;

    public function index(Request $request): JsonResponse
    {
        $faculty = $request->user()->faculty;

        if ($faculty === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'Students retrieved successfully.',
            );
        }

        $query = Student::query()
            ->whereHas('internshipAssignments', fn ($q) => $q->where('faculty_id', $faculty->id))
            ->with(['user', 'department', 'batch'])
            ->with(['internshipAssignments' => fn ($q) => $q->where('faculty_id', $faculty->id)
                ->with('internship.company')
                ->latest('assigned_at'),
            ]);

        if ($departmentId = (int) $request->input('department_id')) {
            $query->where('department_id', $departmentId);
        }

        if ($year = (int) $request->input('year')) {
            $query->where('year', $year);
        }

        if ($status = $request->input('status')) {
            $query->whereHas('internshipAssignments', fn ($q) => $q->where('faculty_id', $faculty->id)->where('status', $status));
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('roll_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $students = $query->paginate($perPage);

        return $this->success(
            data: [
                'items' => FacultyStudentListResource::collection($students->items()),
                'meta' => [
                    'current_page' => $students->currentPage(),
                    'per_page' => $students->perPage(),
                    'total' => $students->total(),
                    'last_page' => $students->lastPage(),
                ],
            ],
            message: 'Students retrieved successfully.',
        );
    }

    /**
     * GET /api/faculty/students/{student} - an assigned student's academic,
     * education and "My Profile" cards. Faculty never see the personal
     * college record (gender, mobile).
     */
    public function show(Request $request, Student $student): JsonResponse
    {
        $faculty = $request->user()->faculty;
        $assigned = $faculty !== null && $student->internshipAssignments()->where('faculty_id', $faculty->id)->exists();

        if (! $assigned) {
            return $this->failure('This student is not assigned to you.', [], 403);
        }

        $student->load(['user.student.department', 'user.student.batch', 'user.student.skills', 'user.student.academicRecords']);

        return $this->success(data: (new StudentProfileResource($student->user))->withoutPersonal());
    }
}
