<?php

namespace App\Enums;

/**
 * A student's "Report a correction" on a locked college-record field.
 */
enum CorrectionRequestStatus: string
{
    case OPEN = 'open';
    case RESOLVED = 'resolved';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
