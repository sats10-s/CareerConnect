<?php

namespace App\Enums;

/**
 * Lifecycle for one internship assignment (the faculty-coordination
 * relationship for a SELECTED internship application) - see
 * App\Models\InternshipAssignment and
 * App\Services\InternshipAssignmentService (the only writer of a new
 * status). ALL-CAPS backed enum, matching the newer convention this
 * schema already uses for ApplicationStatus/InterviewStatus/
 * PlacementDriveStatus - not the older lowercase OpportunityStatus one.
 */
enum InternshipAssignmentStatus: string
{
    case ASSIGNED = 'ASSIGNED';
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::ASSIGNED => in_array($target, [self::ACTIVE, self::CANCELLED], true),
            self::ACTIVE => in_array($target, [self::COMPLETED, self::CANCELLED], true),
            self::COMPLETED, self::CANCELLED => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}
