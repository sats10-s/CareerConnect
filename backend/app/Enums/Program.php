<?php

namespace App\Enums;

/**
 * Derived from the JNTU No: 1A/5A are B.Tech, 1D is M.Tech.
 */
enum Program: string
{
    case BTECH = 'btech';
    case MTECH = 'mtech';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
