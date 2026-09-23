<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for GET /api/admin/applications - see
 * Api\Admin\ApplicationController::index. Adds the student-identity
 * fields an admin management screen needs (name/roll number/
 * department) on top of what ApplicationListResource exposes to the
 * owning student - deliberately a separate class rather than a
 * flag/param on ApplicationListResource, so the two audiences'
 * payloads can never accidentally leak into each other.
 *
 * @mixin \App\Models\Application
 */
class AdminApplicationListResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->user?->name,
                'roll_number' => $this->student->roll_number,
                'department' => $this->student->department?->name,
            ]),
            'opportunity' => $this->opportunitySummary($this->applicable),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
