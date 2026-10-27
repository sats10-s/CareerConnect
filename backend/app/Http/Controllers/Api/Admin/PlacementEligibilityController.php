<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Student;
use App\Services\StudentPlacementStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PlacementEligibilityController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly StudentPlacementStatusService $placementStatuses) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $search = trim((string) $request->query('search', ''));
        $students = Student::with('user:id,name,email')->when($search, fn ($q) => $q->where('roll_number', 'like', "%{$search}%")
            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->limit(25)->get(['id', 'user_id', 'roll_number', 'placement_status', 'placement_company', 'placement_job_role', 'placement_package', 'placement_date', 'placement_status_updated_by', 'placement_status_updated_at']);
        $jobs = JobPosting::query()->when($search, fn ($q) => $q->where('title', 'like', "%{$search}%"))->limit(25)->get(['id', 'title'])->map(fn ($o) => ['id' => $o->id, 'type' => 'job', 'title' => $o->title]);
        $internships = Internship::query()->when($search, fn ($q) => $q->where('title', 'like', "%{$search}%"))->limit(25)->get(['id', 'title'])->map(fn ($o) => ['id' => $o->id, 'type' => 'internship', 'title' => $o->title]);

        return $this->success(data: ['students' => $students, 'opportunities' => $jobs->concat($internships)->values()]);
    }

    public function setStatus(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $data = $request->validate([
            'placement_status' => ['required', Rule::in(['PLACED', 'NOT_PLACED', 'UNKNOWN', 'UNPLACED'])],
            'placement_company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'placement_job_role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'placement_package' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'placement_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $data['placement_status'] = $data['placement_status'] === 'UNPLACED' ? 'NOT_PLACED' : $data['placement_status'];
        $status = $data['placement_status'];
        $reason = $data['reason'] ?? null;
        unset($data['placement_status'], $data['reason']);
        $student = $this->placementStatuses->update($student, $status, $request->user(), $data, $reason);
        return $this->success(data: [
            'id' => $student->id,
            'placement_status' => $student->placement_status,
            'placement_company' => $student->placement_company,
            'placement_job_role' => $student->placement_job_role,
            'placement_package' => $student->placement_package,
            'placement_date' => $student->placement_date?->toDateString(),
            'placement_status_updated_by' => $student->placement_status_updated_by,
            'placement_status_updated_at' => $student->placement_status_updated_at?->toIso8601String(),
        ]);
    }

    public function studentHistory(Student $student): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $history = $student->placementStatusHistory()->with('changedBy:id,name')->get()->map(function ($row) {
            $data = $row->toArray();
            $data['actor'] = $row->changedBy ? ['id' => $row->changedBy->id, 'name' => $row->changedBy->name] : null;
            return $data;
        });
        return $this->success(data: $history);
    }

    public function policy(Request $request, string $type, int $id): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $data = $request->validate(['allow_placed_students' => ['required', 'boolean']]);
        $opportunity = $this->opportunity($type, $id);
        DB::table('opportunity_placement_policies')->updateOrInsert(
            ['opportunity_type' => $opportunity::class, 'opportunity_id' => $id],
            ['allow_placed_students' => $data['allow_placed_students'], 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()],
        );
        return $this->success(data: ['allow_placed_students' => (bool) $data['allow_placed_students']]);
    }

    public function grant(Request $request, string $type, int $id): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $opportunity = $this->opportunity($type, $id);
        $active = DB::table('student_opportunity_placement_exceptions')->where('student_id', $data['student_id'])->where('opportunity_type', $opportunity::class)->where('opportunity_id', $id)->whereNull('revoked_at')->exists();
        if ($active) return $this->failure('An active exception already exists for this student and opportunity.', status: 422);
        $exceptionId = DB::table('student_opportunity_placement_exceptions')->insertGetId([
            'student_id' => $data['student_id'], 'opportunity_type' => $opportunity::class, 'opportunity_id' => $id,
            'grant_reason' => $data['reason'], 'granted_by' => $request->user()->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->success(data: DB::table('student_opportunity_placement_exceptions')->find($exceptionId), status: 201);
    }

    public function revoke(Request $request, int $exceptionId): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $exception = DB::table('student_opportunity_placement_exceptions')->where('id', $exceptionId)->whereNull('revoked_at')->first();
        if (! $exception) return $this->failure('Active exception not found.', status: 404);
        DB::table('student_opportunity_placement_exceptions')->where('id', $exceptionId)->update(['revocation_reason' => $data['reason'], 'revoked_by' => $request->user()->id, 'revoked_at' => now(), 'updated_at' => now()]);
        return $this->success(data: DB::table('student_opportunity_placement_exceptions')->find($exceptionId));
    }

    public function history(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $query = DB::table('student_opportunity_placement_exceptions')->orderByDesc('created_at')->limit(200);
        if ($request->filled('student_id')) $query->where('student_id', $request->integer('student_id'));
        return $this->success(data: $query->get());
    }

    private function opportunity(string $type, int $id): JobPosting|Internship
    {
        abort_unless(in_array($type, ['job', 'internship'], true), 404);
        $class = $type === 'job' ? JobPosting::class : Internship::class;
        return $class::query()->findOrFail($id);
    }
}
