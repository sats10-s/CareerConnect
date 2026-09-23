<?php

namespace App\Enums;

/**
 * What kind of document was uploaded against an internship assignment -
 * see App\Models\InternshipDocument. Purely descriptive/filterable
 * metadata (no transition rules of its own, unlike
 * InternshipDocumentStatus) - ALL-CAPS backed enum, matching the
 * InternshipAssignmentStatus/WeeklyReportStatus convention this schema
 * already uses.
 */
enum InternshipDocumentType: string
{
    case OFFER_LETTER = 'OFFER_LETTER';
    case JOINING_LETTER = 'JOINING_LETTER';
    case INTERNSHIP_CERTIFICATE = 'INTERNSHIP_CERTIFICATE';
    case WORK_EVIDENCE = 'WORK_EVIDENCE';
    case FINAL_REPORT = 'FINAL_REPORT';
    case COMPLETION_CERTIFICATE = 'COMPLETION_CERTIFICATE';
    case OTHER = 'OTHER';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
