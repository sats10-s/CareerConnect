<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/internship-assignments/{assignment}/cancel
 * (see Api\Admin\InternshipAssignmentController::cancel). Mirrors
 * CancelInterviewRequest/CancelPlacementDriveRequest - `reason` is
 * required.
 */
class CancelInternshipAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
