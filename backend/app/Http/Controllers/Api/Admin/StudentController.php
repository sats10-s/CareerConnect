<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StudentDirectoryRequest;
use App\Http\Resources\AdminStudentListResource;
use App\Http\Resources\AdminStudentResource;
use App\Enums\AcademicRecordSource;
use App\Http\Requests\Admin\UpdateStudentAcademicsRequest;
use App\Models\CorrectionRequest;
use App\Services\Student\AcademicRecordService;
use App\Models\Student;
use App\Services\Student\CorrectionRequestService;
use Illuminate\Http\Request;
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

        foreach (['department_id', 'batch_id', 'year', 'entry_type', 'year_of_joining'] as $column) {
            if (! empty($filters[$column])) {
                $query->where("students.{$column}", $filters[$column]);
            }
        }

        if (! empty($filters['section'])) {
            $query->where('students.section', mb_strtoupper(trim($filters['section'])));
        }

        match ($filters['backlogs'] ?? null) {
            'none' => $query->where('students.backlogs', 0),
            'some' => $query->whereBetween('students.backlogs', [1, 2]),
            'many' => $query->where('students.backlogs', '>=', 3),
            'not_reported' => $query->whereNull('students.backlogs'),
            default => null,
        };

        if (isset($filters['cgpa_min']) && $filters['cgpa_min'] !== null) {
            $query->where('students.cgpa', '>=', $filters['cgpa_min']);
        }
        if (isset($filters['cgpa_max']) && $filters['cgpa_max'] !== null) {
            $query->where('students.cgpa', '<=', $filters['cgpa_max']);
        }

        if ($skill = trim((string) ($filters['skill'] ?? ''))) {
            $query->whereHas('skills', fn ($q) => $q->whereRaw('LOWER(skill_name) LIKE ?', ['%'.addcslashes(mb_strtolower($skill), '%_\\').'%']));
        }

        if (($filters['placement'] ?? null) === 'placed') {
            $query->whereHas('applications', fn ($q) => $q->where('status', $selected));
        } elseif (($filters['placement'] ?? null) === 'unplaced') {
            $query->whereDoesntHave('applications', fn ($q) => $q->where('status', $selected));
        }

        $sortColumn = match ($filters['sort'] ?? 'name') {
            'roll_number' => 'students.roll_number',
            'cgpa' => 'students.cgpa',
            'backlogs' => 'students.backlogs',
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
            'user.student.certifications', 'user.student.experiences', 'user.student.academicRecords.changedBy',
            'user.student.correctionRequests.resolvedBy',
            'applications.applicable.company',
            'internshipAssignments.internship.company', 'internshipAssignments.faculty.user',
        ]);

        return $this->success(data: new AdminStudentResource($student));
    }

    /**
     * POST /api/admin/students/{student}/academics - a single CGPA/backlogs
     * change with a reason. Written to the history, audited, and the
     * student is notified; eligibility uses the new numbers at once.
     */
    public function updateAcademics(UpdateStudentAcademicsRequest $request, Student $student, AcademicRecordService $academics): JsonResponse
    {
        Gate::authorize('viewAsAdmin', $student);
        $data = $request->validated();

        $record = $academics->apply(
            $student,
            isset($data['cgpa']) ? round((float) $data['cgpa'], 2) : null,
            isset($data['backlogs']) ? (int) $data['backlogs'] : null,
            AcademicRecordSource::MANUAL,
            $request->user(),
            trim($data['reason']),
            $data['semester_label'] ?? null,
        );

        $student->refresh();

        return $this->success(
            data: ['cgpa' => $student->cgpa === null ? null : (float) $student->cgpa, 'backlogs' => $student->backlogs, 'changed' => $record !== null],
            message: $record ? 'Academics updated. The student has been notified.' : 'Those are already the current values - nothing changed.',
        );
    }

    public function resolveCorrection(Request $request, Student $student, CorrectionRequest $correction, CorrectionRequestService $service): JsonResponse
    {
        Gate::authorize('viewAsAdmin', $student);
        abort_unless($correction->student_id === $student->id, 404);

        $correction = $service->resolve($correction, $request->user());

        return $this->success(data: ['id' => $correction->id, 'status' => $correction->status->value, 'resolved_at' => $correction->resolved_at?->toIso8601String()], message: 'Marked as resolved.');
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
