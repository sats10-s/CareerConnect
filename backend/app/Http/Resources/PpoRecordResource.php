<?php

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Http\Resources\Concerns\FormatsOpportunitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail payload for one PPO record - shared by
 * GET /api/faculty/ppo-records/{ppoRecord} and the base every write
 * action responds with. Student-facing responses
 * (GET /api/student/ppo) also go through THIS class with runtime
 * viewer-role gating; see AdminPpoRecordResource for the admin-only
 * subclass. Mirrors InternshipCompletionResource exactly.
 *
 * @mixin \App\Models\PpoRecord
 */
class PpoRecordResource extends JsonResource
{
    use FormatsOpportunitySummary;

    public function toArray(Request $request): array
    {
        $isAdmin = $this->viewerIsAdmin($request);
        $isFaculty = $this->viewerIsFaculty($request);

        $base = [
            'id' => $this->id,
            'internship_assignment_id' => $this->internship_assignment_id,
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ] : null),
            'offered_at' => $this->offered_at?->toDateString(),
            'joining_date' => $this->joining_date?->toDateString(),
            'designation' => $this->designation,
            'package' => $this->package !== null ? (string) $this->package : null,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($isAdmin || $isFaculty) {
            $base = array_merge($base, [
                'student' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('student') && $this->internshipAssignment->student ? [
                    'id' => $this->internshipAssignment->student->id,
                    'name' => $this->internshipAssignment->student->user?->name,
                    'roll_number' => $this->internshipAssignment->student->roll_number,
                    'department' => $this->internshipAssignment->student->department?->name,
                    'year' => $this->internshipAssignment->student->year,
                ] : null),

                'faculty' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('faculty') && $this->internshipAssignment->faculty ? [
                    'id' => $this->internshipAssignment->faculty->id,
                    'name' => $this->internshipAssignment->faculty->user?->name,
                ] : null),

                'internship' => $this->whenLoaded('internshipAssignment', fn () => $this->internshipAssignment?->relationLoaded('internship')
                    ? $this->opportunitySummary($this->internshipAssignment->internship)
                    : null),

                'history' => $this->whenLoaded('statusHistory', fn () => PpoRecordStatusHistoryResource::collection($this->statusHistory)),
            ]);
        }

        return $base;
    }

    private function viewerIsAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }

    private function viewerIsFaculty(Request $request): bool
    {
        return $request->user()?->roleName() === RoleName::FACULTY;
    }
}
