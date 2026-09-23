<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/student/applications/{application}/withdraw (see
 * Api\Student\ApplicationController::withdraw). Ownership/eligibility-
 * to-withdraw is enforced by ApplicationPolicy::withdraw and
 * App\Services\ApplicationService::withdraw() respectively - this
 * class only validates the optional body shape. A student may withdraw
 * with no explanation at all; `remarks`, if given, is stored on the
 * resulting application_status_history row.
 */
class WithdrawApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
