<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreExperienceRequest;
use App\Http\Requests\Student\UpdateExperienceRequest;
use App\Http\Resources\StudentProfileResource;
use App\Models\StudentExperience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExperienceController extends Controller
{
    use ReturnsFreshProfile;

    public function store(StoreExperienceRequest $request): StudentProfileResource
    {
        $request->user()->student->experiences()->create($request->validated());

        return $this->freshProfile($request);
    }

    public function update(UpdateExperienceRequest $request, StudentExperience $experience): StudentProfileResource
    {
        // IDOR guard - see StudentExperiencePolicy.
        Gate::authorize('update', $experience);

        $experience->update($request->validated());

        return $this->freshProfile($request);
    }

    public function destroy(Request $request, StudentExperience $experience): StudentProfileResource
    {
        Gate::authorize('delete', $experience);

        $experience->delete();

        return $this->freshProfile($request);
    }
}
