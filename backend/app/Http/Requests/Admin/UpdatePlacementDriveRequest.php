<?php

namespace App\Http\Requests\Admin;

use App\Enums\DriveType;
use App\Http\Requests\Concerns\ValidatesDriveScheduling;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/placement-drives/{placementDrive} (see
 * Api\Admin\PlacementDriveController::update) - scheduling/descriptive
 * fields only. `status` is explicitly `prohibited`: a status change
 * always goes through publish()/close()/cancel() (their own dedicated
 * endpoints), never a silent field edit here - mirrors
 * UpdateInterviewRequest's identical `scheduled_at` `prohibited` rule.
 *
 * `company_id` is validated for shape here (must exist), but the
 * "cannot change company once published" business rule depends on the
 * {placementDrive} route model's current status, so it's enforced in
 * App\Services\PlacementDriveService::update(), not here - same
 * precedent as StoreInterviewRequest deferring application-state
 * checks to InterviewService.
 */
class UpdatePlacementDriveRequest extends FormRequest
{
    use ValidatesDriveScheduling;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'drive_type' => ['sometimes', Rule::enum(DriveType::class)],
            'venue' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'application_deadline' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $this->applyDriveSchedulingRule($validator);
    }
}
