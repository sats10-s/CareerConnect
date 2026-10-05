<?php

namespace App\Support;

use App\Enums\EntryType;
use App\Enums\Program;

/**
 * A JNTU hall-ticket number, e.g. 23341A0501:
 *
 *   23   admission year (2023)
 *   341  college code
 *   A    (with the digit before it) the entry code - "1A" regular B.Tech,
 *        "5A" lateral-entry B.Tech, "1D" M.Tech
 *   05   branch code
 *   01   serial
 *
 * Everything here is derived from the number alone; the college file's own
 * values win whenever they are present (see StudentRowCleaner).
 */
final class JntuNumber
{
    public const PATTERN = '/^\d{2}\d{3}[A-Z]\d{2}[A-Z0-9]\d$/';

    public static function normalize(?string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', (string) $value));
    }

    public static function isValid(?string $value): bool
    {
        return (bool) preg_match(self::PATTERN, self::normalize($value));
    }

    public static function admissionYear(string $number): int
    {
        return 2000 + (int) substr(self::normalize($number), 0, 2);
    }

    /** Characters 5-6, e.g. "1A", "5A", "1D". */
    public static function entryCode(string $number): string
    {
        return substr(self::normalize($number), 4, 2);
    }

    public static function entryType(string $number): EntryType
    {
        return self::entryCode($number) === '5A' ? EntryType::LATERAL : EntryType::REGULAR;
    }

    public static function program(string $number): Program
    {
        return str_ends_with(self::entryCode($number), 'D') ? Program::MTECH : Program::BTECH;
    }

    /**
     * Lateral-entry students join in 2nd year, so their batch started the
     * year before their admission year. Regular and M.Tech: same year.
     */
    public static function derivedYearOfJoining(string $number): int
    {
        $year = self::admissionYear($number);

        return self::entryType($number) === EntryType::LATERAL ? $year - 1 : $year;
    }
}
