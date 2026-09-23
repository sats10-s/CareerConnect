<?php

namespace App\Http\Controllers\Api\Faculty;

use App\Exceptions\PpoRecordException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppo\PpoRecordFilterRequest;
use App\Http\Requests\Ppo\StorePpoRecordRequest;
use App\Http\Requests\Ppo\UpdatePpoRecordRequest;
use App\Http\Resources\PpoRecordListResource;
use App\Http\Resources\PpoRecordResource;
use App\Models\InternshipAssignment;
use App\Models\PpoRecord;
use App\Services\InternshipCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A faculty member's own PPO-record workflow - mirrors
 * Api\Faculty\InternshipCompletionController exactly (same
 * faculty-scoped index()/show(), same {assignment}-scoped store()/
 * update()). See StorePpoRecordRequest's docblock for why this route
 * exists even though the module spec's own route list never gave
 * faculty a PPO write path.
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

    public function __construct(private readonly InternshipCompletionService $completionService) {}

    public function index(PpoRecordFilterRequest $request): JsonResponse
    {
        $faculty = $request->user()->faculty;

        if ($faculty === null) {
            return $this->success(
                data: ['items' => [], 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1]],
                message: 'PPO records retrieved successfully.',
            );
        }

        $query = PpoRecord::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('faculty_id', $faculty->id))
            ->with(self::EAGER_LOAD);

        if ($assignmentId = (int) $request->validated('internship_assignment_id')) {
            $query->where('internship_assignment_id', $assignmentId);
        }

        if ($studentId = (int) $request->validated('student_id')) {
            $query->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $studentId));
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
            $query->whereHas('internshipAssignment', function ($q) use ($search) {
                $q->whereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('roll_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                });
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

        return $this->success(data: new PpoRecordResource($ppoRecord), message: 'PPO record retrieved successfully.');
    }

    public function store(StorePpoRecordRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        Gate::authorize('create', [PpoRecord::class, $assignment]);

        try {
            $ppoRecord = $this->completionService->createPpo(
                $assignment,
                $request->user(),
                $request->safe()->only(['offered_at', 'joining_date', 'designation', 'package', 'remarks']),
            );
        } catch (PpoRecordException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $ppoRecord->load(self::EAGER_LOAD);

        return $this->success(data: new PpoRecordResource($ppoRecord), message: 'PPO record created successfully.', status: 201);
    }

    public function update(UpdatePpoRecordRequest $request, InternshipAssignment $assignment): JsonResponse
    {
        $ppoRecord = $assignment->ppoRecord;

        if ($ppoRecord === null) {
            return $this->failure('No PPO record exists yet for this assignment. Use POST to create one.', status: 404);
        }

        Gate::authorize('update', $ppoRecord);

        try {
            $ppoRecord = $this->completionService->updatePpo(
                $ppoRecord,
                $request->user(),
                $request->safe()->only(['offered_at', 'joining_date', 'designation', 'package', 'remarks', 'status']),
            );
        } catch (PpoRecordException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $ppoRecord->load(self::EAGER_LOAD);

        return $this->success(data: new PpoRecordResource($ppoRecord), message: 'PPO record updated successfully.');
    }
}
