<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/admin/students/{student} - the student's profile (the same
 * shape the student sees, via StudentProfileResource) plus what the
 * placement office needs alongside it: every application and every
 * internship assignment.
 *
 * @mixin \App\Models\Student
 */
class AdminStudentResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        return [
            'profile' => new StudentProfileResource($this->user),
            'is_active' => (bool) $this->user?->is_active,
            'applications' => $this->applications
                ->sortByDesc('applied_at')
                ->values()
                ->map(fn ($application) => [
                    'id' => $application->id,
                    'status' => $application->status?->value,
                    'applied_at' => $application->applied_at?->toIso8601String(),
                    'opportunity' => $this->opportunitySummary($application->applicable),
                ]),
            'internship_assignments' => $this->internshipAssignments
                ->sortByDesc('assigned_at')
                ->values()
                ->map(fn ($assignment) => [
                    'id' => $assignment->id,
                    'status' => $assignment->status?->value,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'started_at' => $assignment->started_at?->toIso8601String(),
                    'ended_at' => $assignment->ended_at?->toIso8601String(),
                    'faculty' => $assignment->faculty ? ['id' => $assignment->faculty->id, 'name' => $assignment->faculty->user?->name] : null,
                    'internship' => $this->opportunitySummary($assignment->internship),
                ]),
        ];
    }
}
