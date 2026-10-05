<?php

namespace App\Enums;

/**
 * Regular (1A in the JNTU No) or lateral entry (5A - diploma holders joining in 2nd year).
 */
enum EntryType: string
{
    case REGULAR = 'regular';
    case LATERAL = 'lateral';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
