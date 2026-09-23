<?php

namespace App\Enums;

/**
 * Lifecycle for one weekly progress report - see
 * App\Models\WeeklyProgressReport and
 * App\Services\WeeklyProgressReportService (the only writer of a new
 * status). ALL-CAPS backed enum, matching this schema's newer
 * convention (ApplicationStatus/InterviewStatus/PlacementDriveStatus/
 * InternshipAssignmentStatus/AttendanceStatus).
 *
 * Lifecycle:
 *
 *   DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED (terminal)
 *                                      -> CHANGES_REQUESTED -> SUBMITTED
 *
 * Explicitly disallowed (see canTransitionTo()): APPROVED -> anything,
 * DRAFT -> APPROVED, DRAFT -> UNDER_REVIEW, CHANGES_REQUESTED ->
 * APPROVED. A report can only ever reach APPROVED by first passing
 * through UNDER_REVIEW.
 */
enum WeeklyReportStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case APPROVED = 'APPROVED';

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
            self::DRAFT => $target === self::SUBMITTED,
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
     * Whether a student may edit this report's own content (as opposed
     * to only calling submit()) - DRAFT and CHANGES_REQUESTED, per the
     * module spec's "Allowed only when status is DRAFT or
     * CHANGES_REQUESTED".
     */
    public function isStudentEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::CHANGES_REQUESTED], true);
    }
}
