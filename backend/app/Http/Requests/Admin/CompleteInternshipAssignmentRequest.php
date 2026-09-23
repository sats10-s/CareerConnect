<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/internship-assignments/{assignment}/complete
 * (see Api\Admin\InternshipAssignmentController::complete). Mirrors
 * CompleteInterviewRequest - no required fields; `remarks` is an
 * optional admin note folded into the resulting status history row,
 * `ended_at` optionally overrides the default (now()).
 */
class CompleteInternshipAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
            'ended_at' => ['nullable', 'date'],
        ];
    }
}
