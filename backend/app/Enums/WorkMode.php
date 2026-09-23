<?php

namespace App\Enums;

/**
 * Shared by both JobPosting and Internship.
 */
enum WorkMode: string
{
    case ON_SITE = 'on_site';
    case REMOTE = 'remote';
    case HYBRID = 'hybrid';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $mode) => $mode->value, self::cases());
    }
}
