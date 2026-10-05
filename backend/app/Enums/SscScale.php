<?php

namespace App\Enums;

/**
 * How the college wrote the 10th score: a percentage, or a 10-point CGPA ("9.8", "10 POINTS OUT 10").
 */
enum SscScale: string
{
    case PERCENT = 'percent';
    case CGPA10 = 'cgpa10';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
