<?php

namespace App\Http\Controllers\Api\Student\Concerns;

use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\Request;

/**
 * Every skill/project/certification/experience/resume mutation
 * endpoint returns the requesting student's full, freshly-reloaded
 * profile (including a recalculated completion percentage) rather
 * than just the single created/updated/deleted record, so the
 * frontend can replace its whole local profile state from any one
 * response instead of needing a second round-trip. This is the one
 * place the eager-load list is defined, shared by every Student\*
 * controller (and StudentProfileController) so it can't drift between
 * endpoints.
 */
trait ReturnsFreshProfile
{
    protected function freshProfile(Request $request): StudentProfileResource
    {
        $user = $request->user()->fresh()->load([
            'role',
            'student.department',
            'student.batch',
            'student.skills',
            'student.projects',
            'student.certifications',
            'student.experiences',
            'student.academicRecords.changedBy',
            'student.correctionRequests',
        ]);

        return new StudentProfileResource($user);
    }
}
