<?php

namespace Tests\Unit\Services;

use App\Enums\ExcelExportType;
use App\Enums\ExcelImportType;
use App\Services\Support\SheetTitleSanitizer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the "Invalid character found in sheet title"
 * bug: ExcelImportType::PLACEMENT_STATUS's label(), "Placement/Application
 * Status", contains a "/" and crashed template generation because it
 * reached Worksheet::setTitle() unsanitized (App\Services\ExcelTemplateService
 * used to pass substr($type->label(), 0, 31) straight into setTitle()).
 *
 * These tests prove two things: (a) every CURRENTLY-defined import/export
 * type's label sanitizes to a title PhpSpreadsheet actually accepts, so a
 * workbook can always be generated for it, and (b) App\Services\Support\
 * SheetTitleSanitizer itself is safe for ANY identifier, including one
 * built from every character Excel forbids in a sheet title - so this
 * suite keeps this bug fixed even if a label is edited later or a new
 * import/export type is added whose machine-readable identifier or label
 * happens to contain ":", "\", "/", "?", "*", "[", or "]".
 *
 * A plain PHPUnit\Framework\TestCase (not Tests\TestCase) - SheetTitleSanitizer
 * has no framework dependencies, so nothing here needs the app booted.
 */
class ExcelSheetTitleSanitizerTest extends TestCase
{
    public function test_every_excel_invalid_character_is_removed(): void
    {
        $sanitized = SheetTitleSanitizer::sanitize('A:B\\C/D?E*F[G]H');

        foreach ([':', '\\', '/', '?', '*', '[', ']'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sanitized);
        }

        $this->assertNotSame('', $sanitized);
        $this->assertLessThanOrEqual(31, mb_strlen($sanitized));
    }

    public function test_whitespace_left_by_stripped_characters_is_collapsed_and_trimmed(): void
    {
        $this->assertSame('A B', SheetTitleSanitizer::sanitize('  A   B  '));
        $this->assertSame('A B', SheetTitleSanitizer::sanitize('A/B'));
    }

    public function test_titles_are_capped_at_the_excel_maximum_of_31_characters(): void
    {
        $sanitized = SheetTitleSanitizer::sanitize(str_repeat('x', 50));

        $this->assertSame(31, mb_strlen($sanitized));
    }

    public function test_a_title_that_sanitizes_to_nothing_falls_back_to_a_safe_default(): void
    {
        $this->assertSame('Sheet1', SheetTitleSanitizer::sanitize(':\\/?*[]'));
        $this->assertSame('Sheet1', SheetTitleSanitizer::sanitize(''));
        $this->assertSame('Sheet1', SheetTitleSanitizer::sanitize('   '));
    }

    public function test_a_title_needing_no_sanitization_is_preserved_exactly(): void
    {
        $this->assertSame('Application Activity', SheetTitleSanitizer::sanitize('Application Activity'));
    }

    /**
     * The exact real-world regression: ExcelImportType::PLACEMENT_STATUS's
     * label contains a "/" and used to crash Worksheet::setTitle() outright.
     */
    public function test_the_placement_status_label_which_contains_a_slash_sanitizes_to_a_valid_readable_title(): void
    {
        $sanitized = SheetTitleSanitizer::sanitize(ExcelImportType::PLACEMENT_STATUS->label());

        $this->assertSame('Placement Application Status', $sanitized);
        $this->assertLessThanOrEqual(31, mb_strlen($sanitized));

        // Prove it is genuinely acceptable to PhpSpreadsheet, not just
        // "looks right" - this is what actually threw before the fix.
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle($sanitized);
        $this->assertSame($sanitized, $spreadsheet->getActiveSheet()->getTitle());
    }

    public function test_a_duplicate_title_within_the_same_workbook_is_made_unique_without_exceeding_the_limit(): void
    {
        $first = SheetTitleSanitizer::sanitize('Overview');
        $second = SheetTitleSanitizer::sanitize('Overview', [$first]);
        $third = SheetTitleSanitizer::sanitize('Overview', [$first, $second]);

        $this->assertSame('Overview', $first);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertLessThanOrEqual(31, mb_strlen($second));
        $this->assertLessThanOrEqual(31, mb_strlen($third));
    }

    public function test_disambiguation_still_fits_the_limit_when_the_base_title_is_already_31_characters(): void
    {
        $long = str_repeat('x', 31);
        $first = SheetTitleSanitizer::sanitize($long);
        $second = SheetTitleSanitizer::sanitize($long, [$first]);

        $this->assertSame(31, mb_strlen($first));
        $this->assertLessThanOrEqual(31, mb_strlen($second));
        $this->assertNotSame($first, $second);
    }

    /**
     * Every CURRENTLY-defined import type's label must sanitize to a
     * title PhpSpreadsheet actually accepts via setTitle() - this is
     * exactly what would have caught the original bug before it shipped.
     */
    public function test_every_import_type_label_can_be_set_as_a_worksheet_title(): void
    {
        foreach (ExcelImportType::cases() as $type) {
            $sanitized = SheetTitleSanitizer::sanitize($type->label());

            $this->assertNotSame('', $sanitized, "Empty sheet title for {$type->value}");
            $this->assertLessThanOrEqual(31, mb_strlen($sanitized), "Title too long for {$type->value}");

            $spreadsheet = new Spreadsheet();
            $spreadsheet->getActiveSheet()->setTitle($sanitized);
            $this->assertSame($sanitized, $spreadsheet->getActiveSheet()->getTitle(), "setTitle() rejected the sanitized title for {$type->value}");
        }
    }

    public function test_every_export_type_label_can_be_set_as_a_worksheet_title(): void
    {
        foreach (ExcelExportType::cases() as $type) {
            $sanitized = SheetTitleSanitizer::sanitize($type->label());

            $this->assertNotSame('', $sanitized, "Empty sheet title for {$type->value}");
            $this->assertLessThanOrEqual(31, mb_strlen($sanitized), "Title too long for {$type->value}");

            $spreadsheet = new Spreadsheet();
            $spreadsheet->getActiveSheet()->setTitle($sanitized);
            $this->assertSame($sanitized, $spreadsheet->getActiveSheet()->getTitle(), "setTitle() rejected the sanitized title for {$type->value}");
        }
    }

    /**
     * Simulates a FUTURE import/export type whose machine-readable
     * identifier/label was written with characters Excel forbids in a
     * sheet title - proving the sanitizer (and therefore template/export
     * generation) stays safe even for identifiers that don't exist yet.
     */
    public function test_a_hypothetical_identifier_containing_every_invalid_character_still_yields_a_settable_title(): void
    {
        $hypotheticalLabels = [
            'Q1/Q2 Report',
            'Company: Status',
            'Path\\To\\Report',
            'What? Report',
            'Highlights*',
            'Archive [2026]',
        ];

        foreach ($hypotheticalLabels as $label) {
            $sanitized = SheetTitleSanitizer::sanitize($label);

            $this->assertNotSame('', $sanitized);
            $this->assertLessThanOrEqual(31, mb_strlen($sanitized));

            $spreadsheet = new Spreadsheet();
            $spreadsheet->getActiveSheet()->setTitle($sanitized);
            $this->assertSame($sanitized, $spreadsheet->getActiveSheet()->getTitle(), "setTitle() rejected the sanitized title for hypothetical label \"{$label}\"");
        }
    }
}
