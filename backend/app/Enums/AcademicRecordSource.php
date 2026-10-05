<?php

namespace App\Enums;

/**
 * Where a student_academic_records row came from: a Super Admin file import, or a single edit from the admin Students drawer.
 */
enum AcademicRecordSource: string
{
    case IMPORT = 'import';
    case MANUAL = 'manual';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
