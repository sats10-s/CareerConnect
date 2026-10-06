<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use App\Services\OpportunityEligibilityService;
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
            'criteria' => $this->criteriaCheck(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Applications are never withdrawn when a student's academics change -
     * an open one is just re-checked against the opportunity's enforced
     * criteria so the placement office can see who no longer qualifies.
     *
     * @return array{meets: bool, reasons: list<string>}|null null when there is nothing to check
     */
    private function criteriaCheck(): ?array
    {
        if ($this->status->isTerminal() || ! $this->relationLoaded('student') || $this->student === null || $this->applicable === null) {
            return null;
        }

        $result = app(OpportunityEligibilityService::class)->evaluate($this->student, $this->applicable);

        if (! $result['eligibility_enforced']) {
            return null;
        }

        return ['meets' => $result['eligible'], 'reasons' => array_column($result['reasons'], 'message')];
    }
}
