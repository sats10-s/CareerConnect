<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/internship-assignments/{assignment}/activate
 * (see Api\Admin\InternshipAssignmentController::activate). No
 * required fields - an admin may optionally back-date/override
 * started_at; when omitted, App\Services\InternshipAssignmentService::
 * activate() defaults it to the assignment's existing started_at (if
 * any) or now().
 */
class ActivateInternshipAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'started_at' => ['nullable', 'date'],
        ];
    }
}
