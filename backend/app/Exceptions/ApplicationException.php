<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\ApplicationService for any application-
 * lifecycle business rule violation - opportunity not open for
 * applications, duplicate application, ineligible student, an
 * application that can no longer be withdrawn, an invalid admin status
 * transition. Always caught by the calling controller
 * (Api\Student\OpportunityController::applyToJob/applyToInternship,
 * Api\Student\ApplicationController::withdraw,
 * Api\Admin\ApplicationController::updateStatus) and turned into a
 * {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500.
 *
 * $reasons carries OpportunityEligibilityService's structured
 * {code, message} reason list when the failure is eligibility-related,
 * so the frontend can render it with the same component it already
 * uses for `my_eligibility.reasons`.
 */
class ApplicationException extends RuntimeException
{
    /**
     * @param array<int, array{code: string, message: string}> $reasons
     */
    public function __construct(string $message, private readonly array $reasons = [], private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * @return array<int, array{code: string, message: string}>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    public function status(): int
    {
        return $this->status;
    }
}
