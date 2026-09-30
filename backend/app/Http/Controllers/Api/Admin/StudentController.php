<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StudentDirectoryRequest;
use App\Http\Resources\AdminStudentListResource;
use App\Http\Resources\AdminStudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Placement Admin > Students: the institution-wide student directory.
 * Read-only - student records are created by registration and bulk-
 * updated through Excel Sync (STUDENT_MASTER import); there is no
 * single-student edit endpoint here.
 */
class StudentController extends Controller
{
    use FormatsApiResponse;

    /** Same disk the student's own resume upload writes to (Api\Student\ResumeController). */
    private const RESUME_DISK = 'local';

    public function index(StudentDirectoryRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);

        $filters = $request->validated();
        $selected = ApplicationStatus::SELECTED->value;

        $query = Student::query()
            ->select('students.*')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->with(['user', 'department', 'batch'])
            ->withCount([
                'applications',
                'applications as selected_count' => fn ($q) => $q->where('status', $selected),
            ]);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('students.roll_number', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        foreach (['department_id', 'batch_id', 'year'] as $column) {
            if (! empty($filters[$column])) {
                $query->where("students.{$column}", $filters[$column]);
            }
        }

        if (($filters['placement'] ?? null) === 'placed') {
            $query->whereHas('applications', fn ($q) => $q->where('status', $selected));
        } elseif (($filters['placement'] ?? null) === 'unplaced') {
            $query->whereDoesntHave('applications', fn ($q) => $q->where('status', $selected));
        }

        $sortColumn = match ($filters['sort'] ?? 'name') {
            'roll_number' => 'students.roll_number',
            'cgpa' => 'students.cgpa',
            default => 'users.name',
        };
        $query->orderBy($sortColumn, ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('students.id');

        $students = $query->paginate((int) ($filters['per_page'] ?? 15));

        return $this->success(data: [
            'items' => AdminStudentListResource::collection($students->items()),
            'meta' => [
                'current_page' => $students->currentPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'last_page' => $students->lastPage(),
            ],
        ]);
    }

    public function show(Student $student): JsonResponse
    {
        Gate::authorize('viewAsAdmin', $student);

        $student->load([
            'user.student.department', 'user.student.batch', 'user.student.skills', 'user.student.projects',
            'user.student.certifications', 'user.student.experiences',
            'applications.applicable.company',
            'internshipAssignments.internship.company', 'internshipAssignments.faculty.user',
        ]);

        return $this->success(data: new AdminStudentResource($student));
    }

    public function resume(Student $student): StreamedResponse|JsonResponse
    {
        Gate::authorize('viewAsAdmin', $student);

        if (! $student->resume_path || ! Storage::disk(self::RESUME_DISK)->exists($student->resume_path)) {
            return $this->failure('This student has not uploaded a resume.', [], 404);
        }

        return Storage::disk(self::RESUME_DISK)->download($student->resume_path, $student->resume_original_name ?? 'resume');
    }
}
