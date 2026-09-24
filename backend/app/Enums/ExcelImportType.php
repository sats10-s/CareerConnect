<?php

namespace App\Enums;

/**
 * Excel Sync Center - the 8 import types the module spec's section 2
 * lists, each with its own template (spec section 18) and its own
 * expected-columns/validation/matching rules (see
 * App\Services\ExcelParsingService::columnMapFor() and
 * App\Services\ExcelImportService).
 *
 * PLACEMENT_STATUS/SHORTLIST/ASSESSMENT_RESULT/FINAL_SELECTION are 4
 * distinct spec-required import types (distinct templates, distinct
 * audit `import_type` values) but are NOT 4 distinct pieces of
 * business logic underneath: all four are the same operation - move an
 * existing Application to a new ApplicationStatus - with the SAME
 * Student/Company/Opportunity/Application matching rules and the SAME
 * transition validation (App\Enums\ApplicationStatus::canTransitionTo()).
 * A "Shortlist" sheet and a "Final Selection" sheet are just an
 * Excel-Status-column full of different typical values for the exact
 * same underlying admin action a placement cell would otherwise
 * perform one row at a time via PATCH .../applications/{id}/status.
 * Giving each its own importer class/duplicated matching+validation
 * would directly violate the module spec's own section 34 instruction
 * ("Excel importer must NOT duplicate existing business logic") for no
 * behavioral benefit - see isApplicationStatusFamily() and
 * App\Services\ExcelImportService::confirmApplicationStatusRow(),
 * the single shared code path all four route through.
 */
enum ExcelImportType: string
{
    case STUDENT_MASTER = 'STUDENT_MASTER';
    case PLACEMENT_STATUS = 'PLACEMENT_STATUS';
    case INTERNSHIP_STATUS = 'INTERNSHIP_STATUS';
    case INTERVIEW_SCHEDULE = 'INTERVIEW_SCHEDULE';
    case SHORTLIST = 'SHORTLIST';
    case ASSESSMENT_RESULT = 'ASSESSMENT_RESULT';
    case FINAL_SELECTION = 'FINAL_SELECTION';
    case ATTENDANCE = 'ATTENDANCE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::STUDENT_MASTER => 'Student Master Data',
            self::PLACEMENT_STATUS => 'Placement/Application Status',
            self::INTERNSHIP_STATUS => 'Internship Status',
            self::INTERVIEW_SCHEDULE => 'Interview Schedule',
            self::SHORTLIST => 'Shortlist',
            self::ASSESSMENT_RESULT => 'Assessment Results',
            self::FINAL_SELECTION => 'Final Selection',
            self::ATTENDANCE => 'Attendance',
        };
    }

    /**
     * See this enum's own docblock - all four share one importer.
     */
    public function isApplicationStatusFamily(): bool
    {
        return in_array($this, [self::PLACEMENT_STATUS, self::SHORTLIST, self::ASSESSMENT_RESULT, self::FINAL_SELECTION], true);
    }
}
