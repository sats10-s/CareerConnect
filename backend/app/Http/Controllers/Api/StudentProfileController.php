<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UpdateProfileRequest;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A student viewing/editing their own profile - the full Student
 * Profile module (personal/academic/skills/projects/certifications/
 * experience/links/resume + the deterministic completion percentage).
 * Skills/projects/certifications/experiences/resume each have their
 * own controller under Api\Student\* since each is its own set of CRUD
 * endpoints; this one owns the single profile record itself.
 *
 * Route-gated by auth:sanctum + role:STUDENT (see routes/api.php), so
 * there's no cross-student access to guard against here - a student
 * can only ever read/write their own record via
 * $request->user()->student, never an id supplied by the client.
 */
class StudentProfileController extends Controller
{
    use ReturnsFreshProfile;

    public function show(Request $request): StudentProfileResource
    {
        return $this->freshProfile($request);
    }

    public function update(UpdateProfileRequest $request): StudentProfileResource
    {
        DB::transaction(function () use ($request) {
            $request->user()->student()->update($request->validated());
        });

        return $this->freshProfile($request);
    }
}
