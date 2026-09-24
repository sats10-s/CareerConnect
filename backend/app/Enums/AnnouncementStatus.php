<?php

namespace App\Enums;

/**
 * Lifecycle for one announcement - see App\Models\Announcement and
 * App\Services\AnnouncementService (the only writer of a new status).
 * ALL-CAPS backed enum, canTransitionTo()/isTerminal() pattern
 * mirroring InternshipCompletionStatus/InternshipDocumentStatus
 * exactly.
 *
 * DRAFT -> PUBLISHED -> ARCHIVED. ARCHIVED is terminal - there is
 * deliberately no ARCHIVED -> PUBLISHED transition (no "restore"
 * workflow exists anywhere in this module), which is precisely what
 * satisfies the module spec's "do not allow publishing archived
 * announcements unless explicitly restored through a valid workflow" -
 * since no such workflow is implemented, publishing an archived
 * announcement is simply impossible, not merely blocked by a runtime
 * check. DRAFT -> ARCHIVED is also disallowed: nothing in the module
 * spec asks for archiving something that was never published, and
 * allowing it would let a draft skip the one meaningful state
 * (PUBLISHED, which is also what gates the one-time notification
 * fan-out) a real announcement is expected to pass through.
 */
enum AnnouncementStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case ARCHIVED = 'ARCHIVED';

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
            self::DRAFT => $target === self::PUBLISHED,
            self::PUBLISHED => $target === self::ARCHIVED,
            self::ARCHIVED => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::ARCHIVED;
    }
}
