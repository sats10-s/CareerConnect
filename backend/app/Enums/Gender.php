<?php

namespace App\Enums;

/**
 * Student gender as recorded by the college (Student Data import). Blank in the file stays null.
 */
enum Gender: string
{
    case FEMALE = 'female';
    case MALE = 'male';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
