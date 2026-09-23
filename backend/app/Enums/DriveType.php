<?php

namespace App\Enums;

/**
 * What kind of recruitment event a placement drive is. Purely
 * descriptive/filtering metadata on the drive itself - it does not
 * restrict which opportunity types (JobPosting/Internship) can be
 * attached; App\Services\PlacementDriveService::attachOpportunity()
 * only checks that an attached opportunity belongs to the drive's
 * company, never that it matches `drive_type`.
 */
enum DriveType: string
{
    case CAMPUS_PLACEMENT = 'CAMPUS_PLACEMENT';
    case INTERNSHIP = 'INTERNSHIP';
    case BOTH = 'BOTH';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
