<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\InterviewListResource;
use App\Http\Resources\InterviewResource;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A student's own interviews - list, detail. Ownership is always
 * derived by walking interview -> application -> student (see
 * InterviewPolicy::owns()); never trusted from the URL/request.
 */
class InterviewController extends Controller
{
    use FormatsApiResponse;

    public function index(Request $request): JsonResponse
    {
        $student = $request->user()->student;

        if (! $student) {
            return $this->failure('Your student profile could not be found.', status: 422);
        }

        $query = Interview::query()
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->with('application.applicable.company');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->input('when') === 'upcoming') {
            $query->where('scheduled_at', '>=', now());
        } elseif ($request->input('when') === 'past') {
            $query->where('scheduled_at', '<', now());
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        // Sorted soonest-first - a student wants to see their next
        // interview first, unlike the admin listing's newest-created-
        // first convention (see Api\Admin\InterviewController::index).
        $interviews = $query->orderBy('scheduled_at')->paginate($perPage);

        return $this->success(
            data: [
                'items' => InterviewListResource::collection($interviews->items()),
                'meta' => [
                    'current_page' => $interviews->currentPage(),
                    'per_page' => $interviews->perPage(),
                    'total' => $interviews->total(),
                    'last_page' => $interviews->lastPage(),
                ],
            ],
            message: 'Interviews retrieved successfully.',
        );
    }

    public function show(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        $interview->load('application.applicable.company');

        return $this->success(data: new InterviewResource($interview), message: 'Interview retrieved successfully.');
    }
}
