<?php

namespace App\Enums;

/**
 * The faculty's final assessment of an internship - see
 * App\Models\InternshipCompletion. Purely descriptive (no transition
 * rules of its own - the RECORD's own lifecycle, DRAFT vs FINALIZED, is
 * tracked separately by App\Enums\InternshipCompletionStatus; this enum
 * is just the "did the internship actually complete" verdict a faculty
 * member records, and can be changed freely while the record is still
 * DRAFT). ALL-CAPS backed enum, matching this schema's existing
 * convention.
 */
enum InternshipCompletionOutcome: string
{
    case COMPLETED = 'COMPLETED';
    case NOT_COMPLETED = 'NOT_COMPLETED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $outcome) => $outcome->value, self::cases());
    }
}
