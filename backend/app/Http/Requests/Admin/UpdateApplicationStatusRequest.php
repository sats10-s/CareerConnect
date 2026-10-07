<?php

namespace App\Http\Requests\Admin;

use App\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/admin/applications/{application}/status (see
 * Api\Admin\ApplicationController::updateStatus). Route-gated by
 * auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN, plus
 * ApplicationPolicy::updateStatus - this class only validates shape.
 *
 * WITHDRAWN is deliberately rejected here even though it's a valid
 * ApplicationStatus value: withdrawal is a student-initiated action
 * (see Api\Student\ApplicationController::withdraw /
 * POST /student/applications/{application}/withdraw) with its own
 * `withdrawn_at` timestamp and ownership rules - an admin can shortlist,
 * reject, etc., but never force an application into WITHDRAWN through
 * this endpoint. The actual from -> to transition legality (e.g.
 * SELECTED can't move anywhere) is checked separately in
 * App\Services\ApplicationService via
 * ApplicationStatus::canTransitionTo(), since that depends on the
 * application's CURRENT status, not just the shape of this request.
 */
class UpdateApplicationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', function ($attribute, $value, $fail) {
                $status = ApplicationStatus::tryFrom((string) $value);

                if (! $status) {
                    $fail('The selected status is invalid.');

                    return;
                }

                if ($status === ApplicationStatus::WITHDRAWN) {
                    $fail('An application can only be withdrawn by the student, not set to WITHDRAWN by an admin.');
                }
            }],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // Annual package in rupees; only kept when selecting.
            'offer_package' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
