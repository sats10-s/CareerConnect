<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreCertificationRequest;
use App\Http\Requests\Student\UpdateCertificationRequest;
use App\Http\Resources\StudentProfileResource;
use App\Models\StudentCertification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CertificationController extends Controller
{
    use ReturnsFreshProfile;

    public function store(StoreCertificationRequest $request): StudentProfileResource
    {
        $request->user()->student->certifications()->create($request->validated());

        return $this->freshProfile($request);
    }

    public function update(UpdateCertificationRequest $request, StudentCertification $certification): StudentProfileResource
    {
        // IDOR guard - see StudentCertificationPolicy.
        Gate::authorize('update', $certification);

        $certification->update($request->validated());

        return $this->freshProfile($request);
    }

    public function destroy(Request $request, StudentCertification $certification): StudentProfileResource
    {
        Gate::authorize('delete', $certification);

        $certification->delete();

        return $this->freshProfile($request);
    }
}
