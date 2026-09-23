<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreSkillRequest;
use App\Http\Resources\StudentProfileResource;
use App\Models\StudentSkill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A student's own skills. Only POST (add) and DELETE (remove) exist -
 * editing a skill is delete + re-add, not an update endpoint - per the
 * module spec ("multiple individual skills...Add/remove").
 */
class SkillController extends Controller
{
    use ReturnsFreshProfile;

    public function store(StoreSkillRequest $request): StudentProfileResource
    {
        $request->user()->student->skills()->create($request->validated());

        return $this->freshProfile($request);
    }

    public function destroy(Request $request, StudentSkill $skill): StudentProfileResource
    {
        // IDOR guard: {skill} above binds to ANY skill row by id, so this
        // authorize() call - backed by StudentSkillPolicy - is what stops
        // a student deleting another student's skill by changing the id
        // in the URL. A skill owned by someone else yields 403.
        Gate::authorize('delete', $skill);

        $skill->delete();

        return $this->freshProfile($request);
    }
}
