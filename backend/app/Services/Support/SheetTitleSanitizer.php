<?php

namespace App\Services\Support;

/**
 * Centralized, single source of truth for turning any string - an
 * ExcelImportType/ExcelExportType label(), a literal sheet name written
 * inline by a developer, or any other candidate title - into something
 * PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::setTitle() can accept
 * without throwing "Invalid character found in sheet title".
 *
 * Excel's own worksheet-title rules (which PhpSpreadsheet enforces at
 * setTitle() time) are: none of : \ / ? * [ ] may appear anywhere in
 * the title, the title may not be empty, and it may not exceed 31
 * characters. Every setTitle() call anywhere in the Excel Sync Center
 * (App\Services\ExcelTemplateService, App\Services\ExcelExportService)
 * MUST route its value through sanitize() rather than ever calling
 * setTitle() with a raw type/label/filename-derived/user-controlled
 * value directly - this is exactly the bug that made the
 * PLACEMENT_STATUS template ("Placement/Application Status", which
 * contains a "/") crash template generation: nothing sanitized it
 * before it reached setTitle().
 */
final class SheetTitleSanitizer
{
    private const MAX_LENGTH = 31;

    private const FALLBACK_TITLE = 'Sheet1';

    /**
     * Every character Excel itself forbids in a worksheet title.
     *
     * @var list<string>
     */
    private const INVALID_CHARACTERS = [':', '\\', '/', '?', '*', '[', ']'];

    /**
     * @param  array<int, string>  $existingTitles  Titles already assigned to
     *   other sheets in the SAME workbook. PhpSpreadsheet will happily let
     *   two sheets share one title, but Excel itself refuses to open a
     *   workbook like that - so a post-sanitization collision is made
     *   unique here rather than silently duplicated.
     */
    public static function sanitize(string $title, array $existingTitles = []): string
    {
        $clean = self::stripInvalidCharacters($title);

        if ($clean === '') {
            $clean = self::FALLBACK_TITLE;
        }

        if (! in_array($clean, $existingTitles, true)) {
            return $clean;
        }

        return self::disambiguate($clean, $existingTitles);
    }

    /**
     * Invalid characters are replaced with a space (not simply deleted)
     * so a title like "Placement/Application Status" reads as
     * "Placement Application Status" rather than the harder-to-read,
     * word-merging "PlacementApplication Status" - preserving a
     * readable, distinct title is the whole point of sanitizing rather
     * than just rejecting the input.
     */
    private static function stripInvalidCharacters(string $title): string
    {
        $clean = str_replace(self::INVALID_CHARACTERS, ' ', $title);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);
        $clean = mb_substr($clean, 0, self::MAX_LENGTH);

        // Truncation can leave trailing whitespace behind (a long title
        // sliced mid-word); tidy it up rather than leaving it dangling.
        return trim($clean);
    }

    /**
     * Appends " (2)", " (3)", ... - the same convention Excel itself
     * uses when a sheet is copied - shrinking the base title as needed
     * so the result never exceeds MAX_LENGTH.
     *
     * @param  array<int, string>  $existingTitles
     */
    private static function disambiguate(string $base, array $existingTitles): string
    {
        for ($suffixNumber = 2; $suffixNumber < 1000; $suffixNumber++) {
            $suffix = ' ('.$suffixNumber.')';
            $available = max(self::MAX_LENGTH - mb_strlen($suffix), 1);
            $candidate = mb_substr($base, 0, $available).$suffix;

            if (! in_array($candidate, $existingTitles, true)) {
                return $candidate;
            }
        }

        // Unreachable in practice - no workbook this module builds comes
        // anywhere near 1000 sheets - but falls back to a truncated,
        // non-unique title rather than looping forever.
        return mb_substr($base, 0, self::MAX_LENGTH);
    }
}
