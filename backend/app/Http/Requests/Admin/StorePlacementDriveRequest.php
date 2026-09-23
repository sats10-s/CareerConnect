<?php

namespace App\Http\Requests\Admin;

use App\Enums\DriveType;
use App\Http\Requests\Concerns\ValidatesDriveScheduling;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/placement-drives (see
 * Api\Admin\PlacementDriveController::store). Route-gated by
 * auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN, plus
 * PlacementDrivePolicy::create - this class only validates field
 * shape. `created_by` is never accepted from the client - the
 * controller derives it from $request->user() and it is not in
 * rules() at all, so even a submitted `created_by` key is silently
 * dropped by $request->validated().
 */
class StorePlacementDriveRequest extends FormRequest
{
    use ValidatesDriveScheduling;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'drive_type' => ['required', Rule::enum(DriveType::class)],
            'venue' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'application_deadline' => ['nullable', 'date'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $this->applyDriveSchedulingRule($validator);
    }
}
