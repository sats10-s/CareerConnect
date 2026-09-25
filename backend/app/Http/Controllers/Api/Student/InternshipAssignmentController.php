<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\InternshipAssignmentResource;
use App\Models\InternshipAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's own internship assignment. Added during the Student
 * role frontend build-out (Phase 1 inventory found the gap): every
 * assignment-scoped sub-resource a student can already reach -
 * Attendance Management ({assignment}/attendance), Weekly Progress
 * Reports ({assignment}/weekly-reports), Internship Document
 * Management (internship_assignment_id in the upload body) - needs an
 * internship_assignment id, but no student-facing route ever exposed
 * one; only admin (`admin/internship-assignments`) and faculty
 * (`faculty/internship-assignments`, view-only) had index/show routes.
 * App\Policies\InternshipAssignmentPolicy::studentOwns() already
 * existed (added for the Attendance module's Gate::authorize() calls
 * on the sub-resource routes) - this controller is the first thing
 * that actually routes a student to it directly.
 *
 * Mirrors Student\InternshipCompletionController /
 * Student\PpoRecordController exactly: a singular `GET
 * /api/student/internship-assignment` route, no {assignment} segment,
 * `data: null` (not a 404) when the student has none yet - the
 * ordinary state before they've been selected for an internship.
 * "Most recent" (by assigned_at, then created_at) mirrors those same
 * two controllers' own tiebreak; the ordinary case is exactly one
 * assignment per student, but this degrades sensibly if a CANCELLED
 * assignment were ever followed by a new one instead of erroring.
 *
 * Reuses InternshipAssignmentResource as-is rather than adding a new
 * Resource class: its own viewerIsAdmin() gate already hides the
 * admin-only notes/assigned_by/timestamps from a non-admin viewer, so
 * a student gets exactly the same safe subset a faculty coordinator
 * sees (see that Resource's docblock - roll_number/department/year
 * via the student block, never cgpa/backlogs/resume/personal links).
 */
class InternshipAssignmentController extends Controller
{
    use FormatsApiResponse;

    public function show(Request $request): JsonResponse
    {
        $student = $request->user()->student;

        if ($student === null) {
            return $this->success(data: null, message: 'No internship assignment found.');
        }

        $assignment = InternshipAssignment::query()
            ->where('student_id', $student->id)
            ->with([
                'faculty.user',
                'internship.company',
                'application',
                'statusHistory' => fn ($q) => $q->orderBy('created_at')->with('changedBy'),
            ])
            ->orderByDesc('assigned_at')
            ->orderByDesc('created_at')
            ->first();

        if ($assignment === null) {
            return $this->success(data: null, message: 'No internship assignment found.');
        }

        return $this->success(
            data: new InternshipAssignmentResource($assignment),
            message: 'Internship assignment retrieved successfully.',
        );
    }
}
