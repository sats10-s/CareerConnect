<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/admin/internship-assignments/{assignment}/faculty
 * (see Api\Admin\InternshipAssignmentController::reassignFaculty /
 * App\Services\InternshipAssignmentService::reassignFaculty()).
 * Whether the new faculty is active, holds the FACULTY role, differs
 * from the current one, and whether the assignment is still in a
 * reassignable (non-terminal) state, are all business-state checks
 * that live in the service, not here.
 */
class ReassignInternshipAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'faculty_id' => ['required', 'integer', 'exists:faculty,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
