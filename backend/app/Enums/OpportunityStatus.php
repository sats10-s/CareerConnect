<?php

namespace App\Enums;

/**
 * Lifecycle status for a JobPosting/Internship. Deliberately separate
 * from the existing `is_active` boolean on both tables (kept untouched
 * - see the opportunity-fields migrations): `is_active` toggles simple
 * visibility, while `status` is the actual publish workflow a
 * placement admin drives an opportunity through.
 */
enum OpportunityStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case CLOSED = 'closed';
    case ARCHIVED = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /**
     * Valid forward transitions: draft -> published/archived,
     * published -> closed/archived, closed -> archived. Archived is
     * terminal. Same-status "transitions" (no-ops) are allowed by the
     * caller separately - this only governs actual status changes.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::PUBLISHED, self::ARCHIVED], true),
            self::PUBLISHED => in_array($target, [self::CLOSED, self::ARCHIVED], true),
            self::CLOSED => $target === self::ARCHIVED,
            self::ARCHIVED => false,
        };
    }
}
