<?php

namespace App\Enums;

/**
 * Job-posting-specific - internships have no employment_type column
 * (their "duration" is already captured by the existing
 * `duration_months` column instead).
 */
enum EmploymentType: string
{
    case FULL_TIME = 'full_time';
    case PART_TIME = 'part_time';
    case CONTRACT = 'contract';
    case TEMPORARY = 'temporary';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
