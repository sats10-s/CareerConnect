<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\PpoRecordResource;
use App\Models\PpoRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's own PPO record. Per the module spec's exact
 * `GET /api/student/ppo` route (singular, no {ppoRecord} segment)
 * mirrors Student\InternshipCompletionController exactly: returns the
 * most recently offered PPO record (by offered_at, then created_at)
 * among the student's own internship assignments, or `data: null` when
 * none exists yet - a PPO is entirely optional, so having none is the
 * ordinary case, not an error. No create/update action here - a
 * student can view their own PPO but never modify it.
 */
class PpoRecordController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship',
        'company',
    ];

    public function show(Request $request): JsonResponse
    {
        $student = $request->user()->student;

        if ($student === null) {
            return $this->success(data: null, message: 'No PPO record found.');
        }

        $ppoRecord = PpoRecord::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $student->id))
            ->with(self::EAGER_LOAD)
            ->orderByDesc('offered_at')
            ->orderByDesc('created_at')
            ->first();

        if ($ppoRecord === null) {
            return $this->success(data: null, message: 'No PPO record found.');
        }

        return $this->success(data: new PpoRecordResource($ppoRecord), message: 'PPO record retrieved successfully.');
    }
}
