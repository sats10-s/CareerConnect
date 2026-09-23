<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/internship-assignments (see
 * Api\Admin\InternshipAssignmentController::store /
 * App\Services\InternshipAssignmentService::create()). Deliberately
 * has NO student_id/internship_id fields - both are always derived
 * from the selected application server-side (see the module spec's
 * explicit "Do NOT require student_id / internship_id"). Whether the
 * referenced application is actually SELECTED, for an internship, and
 * not already assigned, and whether the referenced faculty is active
 * and holds the FACULTY role, are all business-state checks that
 * depend on those rows' CURRENT state, not just this request's own
 * shape - so they live in the service, not here (same reasoning as
 * StoreInterviewRequest's docblock).
 */
class StoreInternshipAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'faculty_id' => ['required', 'integer', 'exists:faculty,id'],
            'assigned_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
