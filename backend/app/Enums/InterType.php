<?php

namespace App\Enums;

/**
 * The "Inter Percentage/Diploma" column holds a diploma score for lateral-entry students, an Intermediate score otherwise.
 */
enum InterType: string
{
    case INTER = 'inter';
    case DIPLOMA = 'diploma';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
