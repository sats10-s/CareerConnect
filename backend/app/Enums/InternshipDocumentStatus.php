<?php

namespace App\Enums;

/**
 * Review lifecycle for one uploaded internship document - see
 * App\Models\InternshipDocument and App\Services\InternshipDocumentService
 * (the only writer of a new status). ALL-CAPS backed enum,
 * canTransitionTo()/isTerminal() pattern mirroring
 * InternshipAssignmentStatus/WeeklyReportStatus exactly.
 *
 * SUBMITTED -> UNDER_REVIEW -> {APPROVED | CHANGES_REQUESTED ->
 * SUBMITTED}. APPROVED is terminal - no further transition is ever
 * allowed, matching the module spec's "APPROVED documents cannot be
 * changed". A fresh upload and a CHANGES_REQUESTED resubmission both
 * land back on SUBMITTED - see
 * InternshipDocumentService::upload()/resubmit() - deliberately never
 * auto-advancing to UNDER_REVIEW on its own (per the spec's "do not
 * introduce unnecessary automatic status transitions"); moving a
 * document into review is always an explicit faculty/admin action.
 */
enum InternshipDocumentStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case APPROVED = 'APPROVED';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';

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
            self::SUBMITTED => $target === self::UNDER_REVIEW,
            self::UNDER_REVIEW => in_array($target, [self::APPROVED, self::CHANGES_REQUESTED], true),
            self::CHANGES_REQUESTED => $target === self::SUBMITTED,
            self::APPROVED => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::APPROVED;
    }

    /**
     * Whether a document in this status may be resubmitted (a new file
     * uploaded in place of the old one) - only ever true for
     * CHANGES_REQUESTED, per the module spec's explicit "Resubmission
     * is allowed only when status is CHANGES_REQUESTED" (a fresh
     * SUBMITTED/UNDER_REVIEW document is not re-uploadable through this
     * action at all - there is simply no product need for it, and
     * introducing one would be exactly the kind of unnecessary
     * automatic/extra transition the spec asks to avoid).
     */
    public function isResubmittable(): bool
    {
        return $this === self::CHANGES_REQUESTED;
    }
}
