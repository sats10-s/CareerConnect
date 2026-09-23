<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/placement-drives/{placementDrive}/cancel -
 * mirrors CancelInterviewRequest exactly.
 */
class CancelPlacementDriveRequest extends FormRequest
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
