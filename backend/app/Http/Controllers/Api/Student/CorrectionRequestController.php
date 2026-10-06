<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProfileResource;
use App\Models\CorrectionRequest;
use App\Services\Student\CorrectionRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A student reporting that a locked college-record field is wrong. */
class CorrectionRequestController extends Controller
{
    use ReturnsFreshProfile;

    public function __construct(private readonly CorrectionRequestService $service) {}

    public function store(Request $request): StudentProfileResource
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(array_keys(CorrectionRequest::FIELDS))],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'note.required' => 'Tell the placement office what the correct value is.',
            'note.min' => 'Add a little more detail so the placement office can fix it.',
        ]);

        $this->service->create($request->user()->student, $data['field'], trim($data['note']));

        return $this->freshProfile($request);
    }
}
