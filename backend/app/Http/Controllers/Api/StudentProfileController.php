<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

/**
 * A student viewing/editing their own profile. Distinct from
 * AuthController@me: that endpoint is the app-boot "who am I"
 * check used everywhere, this one is specifically the editable
 * profile screen and is the only place a student can change their
 * roll_number/department/batch/year/cgpa/backlogs/phone.
 *
 * Route-gated by auth:sanctum + role:STUDENT (see routes/api.php),
 * so there's no cross-student access to guard against here - a
 * student can only ever read/write their own record via
 * $request->user()->student.
 */
class StudentProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        $user = $request->user()->load(['role', 'student.department', 'student.batch']);

        return new UserResource($user);
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        $request->user()->student()->update($request->validated());

        $user = $request->user()->fresh()->load(['role', 'student.department', 'student.batch']);

        return new UserResource($user);
    }
}
