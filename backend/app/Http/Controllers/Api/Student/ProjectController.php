<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreProjectRequest;
use App\Http\Requests\Student\UpdateProjectRequest;
use App\Http\Resources\StudentProfileResource;
use App\Models\StudentProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    use ReturnsFreshProfile;

    public function store(StoreProjectRequest $request): StudentProfileResource
    {
        $request->user()->student->projects()->create($request->validated());

        return $this->freshProfile($request);
    }

    public function update(UpdateProjectRequest $request, StudentProject $project): StudentProfileResource
    {
        // IDOR guard - see StudentProjectPolicy. {project} binds to any
        // project row by id; this is what stops editing someone else's.
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return $this->freshProfile($request);
    }

    public function destroy(Request $request, StudentProject $project): StudentProfileResource
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return $this->freshProfile($request);
    }
}
