<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\InternshipCompletionResource;
use App\Models\InternshipCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's own internship-completion record. Per the module spec's
 * exact `GET /api/student/internship-completion` route (singular, no
 * {completion} segment and no index/list action) this returns, not a
 * paginated collection, but a SINGLE record: the most recently dated
 * completion (by completion_date, then created_at as a tiebreaker)
 * among the student's OWN internship assignments - the ordinary case
 * of exactly one internship per student. Ownership is always derived
 * by walking completion -> internshipAssignment -> student_id, NEVER
 * trusted from the URL/request; there is no way for a student to
 * request another student's record through this endpoint at all (no
 * id parameter exists to spoof). There is no create/update/finalize
 * action here - a student can view their own completion but never
 * modify it (see App\Policies\InternshipCompletionPolicy's docblock).
 *
 * When the student has no completion record yet (the ordinary case for
 * most of an internship's lifetime - a completion is only recorded
 * once a faculty member closes it out), this returns success with
 * `data: null` rather than a 404 - the absence of a completion record
 * is not an error, it is the expected steady state.
 */
class InternshipCompletionController extends Controller
{
    use FormatsApiResponse;

    private const EAGER_LOAD = [
        'internshipAssignment.student.user',
        'internshipAssignment.faculty.user',
        'internshipAssignment.internship.company',
    ];

    public function show(Request $request): JsonResponse
    {
        $student = $request->user()->student;

        if ($student === null) {
            return $this->success(data: null, message: 'No completion record found.');
        }

        $completion = InternshipCompletion::query()
            ->whereHas('internshipAssignment', fn ($q) => $q->where('student_id', $student->id))
            ->with(self::EAGER_LOAD)
            ->orderByDesc('completion_date')
            ->orderByDesc('created_at')
            ->first();

        if ($completion === null) {
            return $this->success(data: null, message: 'No completion record found.');
        }

        return $this->success(data: new InternshipCompletionResource($completion), message: 'Completion record retrieved successfully.');
    }
}
