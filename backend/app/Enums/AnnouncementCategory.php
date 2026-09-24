<?php

namespace App\Enums;

/**
 * Free-form categorization for admin-created announcements - see
 * App\Models\Announcement. ALL-CAPS backed enum, matching this
 * schema's existing convention; never an arbitrary free-text column
 * (see StoreAnnouncementRequest/UpdateAnnouncementRequest, both of
 * which validate this via Rule::enum() - an unrecognized category
 * value is rejected outright, never silently accepted).
 */
enum AnnouncementCategory: string
{
    case GENERAL = 'GENERAL';
    case PLACEMENT = 'PLACEMENT';
    case INTERNSHIP = 'INTERNSHIP';
    case TRAINING = 'TRAINING';
    case DEADLINE = 'DEADLINE';
    case EVENT = 'EVENT';
    case IMPORTANT = 'IMPORTANT';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category) => $category->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'General',
            self::PLACEMENT => 'Placement',
            self::INTERNSHIP => 'Internship',
            self::TRAINING => 'Training',
            self::DEADLINE => 'Deadline',
            self::EVENT => 'Event',
            self::IMPORTANT => 'Important',
        };
    }
}
