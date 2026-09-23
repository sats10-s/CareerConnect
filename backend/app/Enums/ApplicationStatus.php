<?php

namespace App\Enums;

/**
 * Full application lifecycle for the `applications` table - see
 * App\Models\Application and App\Services\ApplicationService (the only
 * place that ever writes a new status). Replaces this module's earlier
 * five-value placeholder set (applied/shortlisted/interview/selected/
 * rejected) - safe to redefine outright since no Application row has
 * ever been persisted against those values (the table was created as
 * an explicit placeholder in the Jobs & Internships Backend Foundation
 * increment and the Application Lifecycle increment is the first thing
 * to actually write to it).
 *
 * canTransitionTo() is the ADMIN-driven transition graph only (see
 * Api\Admin\ApplicationController::updateStatus /
 * App\Http\Requests\Admin\UpdateApplicationStatusRequest, which
 * separately refuses WITHDRAWN as a PATCH target outright). Withdrawal
 * is intentionally NOT part of this graph - it is a student-initiated
 * action with its own, more permissive rule (see isWithdrawable()
 * below), because the module spec requires withdrawal to work from
 * ASSESSMENT and INTERVIEW too, which this admin transition table does
 * not otherwise reach WITHDRAWN from. Keeping the two rules separate
 * (rather than stretching canTransitionTo() to cover both actors) lets
 * each one match its own spec section exactly.
 */
enum ApplicationStatus: string
{
    case APPLIED = 'APPLIED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case SHORTLISTED = 'SHORTLISTED';
    case ASSESSMENT = 'ASSESSMENT';
    case INTERVIEW = 'INTERVIEW';
    case SELECTED = 'SELECTED';
    case REJECTED = 'REJECTED';
    case WITHDRAWN = 'WITHDRAWN';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /**
     * The admin-driven transition graph. SELECTED/REJECTED/WITHDRAWN
     * are terminal - nothing transitions out of them.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::APPLIED => in_array($target, [self::UNDER_REVIEW, self::WITHDRAWN], true),
            self::UNDER_REVIEW => in_array($target, [self::SHORTLISTED, self::REJECTED, self::WITHDRAWN], true),
            self::SHORTLISTED => in_array($target, [self::ASSESSMENT, self::INTERVIEW, self::REJECTED, self::WITHDRAWN], true),
            self::ASSESSMENT => in_array($target, [self::INTERVIEW, self::SELECTED, self::REJECTED], true),
            self::INTERVIEW => in_array($target, [self::SELECTED, self::REJECTED], true),
            self::SELECTED, self::REJECTED, self::WITHDRAWN => false,
        };
    }

    /**
     * Whether a student may still withdraw an application currently in
     * this status - every non-terminal status (APPLIED, UNDER_REVIEW,
     * SHORTLISTED, ASSESSMENT, INTERVIEW). See
     * App\Services\ApplicationService::withdraw().
     */
    public function isWithdrawable(): bool
    {
        return ! in_array($this, [self::SELECTED, self::REJECTED, self::WITHDRAWN], true);
    }

    /**
     * Terminal statuses never transition anywhere, by either actor.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::SELECTED, self::REJECTED, self::WITHDRAWN], true);
    }
}
