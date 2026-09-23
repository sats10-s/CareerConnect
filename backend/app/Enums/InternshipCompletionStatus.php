<?php

namespace App\Enums;

/**
 * The RECORD lifecycle of one InternshipCompletion - not to be confused
 * with App\Enums\InternshipCompletionOutcome (the faculty's COMPLETED/
 * NOT_COMPLETED verdict, a content field that may be edited freely
 * while still DRAFT). DRAFT -> FINALIZED is the only transition, and it
 * is terminal - once FINALIZED, the record (outcome, final_remarks,
 * completion_certificate_document_id, completion_date) can never be
 * changed again, per the module spec's "Finalized completion is
 * immutable". This column/enum is a necessary addition beyond the
 * module spec's literal internship_completions field list - the spec
 * asks for a distinct "finalize completion" service action and an
 * immutability rule, and this DRAFT/FINALIZED status (with its own
 * append-only internship_completion_status_history, mirroring
 * InternshipDocumentStatus/WeeklyReportStatus's own history pattern
 * exactly) is how that is represented, rather than inventing a
 * boolean flag with no audit trail. ALL-CAPS backed enum,
 * canTransitionTo()/isTerminal() pattern mirroring
 * InternshipDocumentStatus/InternshipAssignmentStatus exactly.
 */
enum InternshipCompletionStatus: string
{
    case DRAFT = 'DRAFT';
    case FINALIZED = 'FINALIZED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => $target === self::FINALIZED,
            self::FINALIZED => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::FINALIZED;
    }
}
