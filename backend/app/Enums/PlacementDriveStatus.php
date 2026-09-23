<?php

namespace App\Enums;

/**
 * Lifecycle for a placement drive - see App\Models\PlacementDrive and
 * App\Services\PlacementDriveService (the only place that writes a new
 * status). Uses the same ALL-CAPS string-backed-enum convention as
 * App\Enums\ApplicationStatus/App\Enums\InterviewStatus - deliberately
 * NOT the older lowercase App\Enums\OpportunityStatus convention
 * (JobPosting/Internship predate this module and are left untouched).
 *
 * DRAFT -> PUBLISHED / CANCELLED
 * PUBLISHED -> CLOSED / CANCELLED
 * CLOSED and CANCELLED are terminal - no further transitions.
 */
enum PlacementDriveStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case CLOSED = 'CLOSED';
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
            self::DRAFT => in_array($target, [self::PUBLISHED, self::CANCELLED], true),
            self::PUBLISHED => in_array($target, [self::CLOSED, self::CANCELLED], true),
            self::CLOSED, self::CANCELLED => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOSED, self::CANCELLED], true);
    }
}
