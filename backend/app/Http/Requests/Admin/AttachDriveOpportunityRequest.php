<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/placement-drives/{placementDrive}/opportunities
 * (see Api\Admin\PlacementDriveController::attachOpportunity). This
 * class only validates field shape - `opportunity_type` must be one of
 * the two short, API-facing strings this app already uses elsewhere
 * for the same job/internship distinction (see
 * Api\Student\OpportunityController's own `type` query param).
 * Existence of the opportunity, that it belongs to the drive's
 * company, and duplicate-attachment prevention all depend on the
 * {placementDrive} route model and the opportunity row itself, so
 * those business rules are enforced in
 * App\Services\PlacementDriveService::attachOpportunity(), not here.
 */
class AttachDriveOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opportunity_type' => ['required', Rule::in(['job', 'internship'])],
            'opportunity_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
