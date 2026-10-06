<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\CompanyRegistrationService for an invalid review
 * transition (e.g. suspending a company that was never approved). Caught by
 * Api\SuperAdmin\CompanyApprovalController and returned as a
 * {success:false, message, errors} response. Mirrors PlacementDriveException.
 */
class CompanyReviewException extends RuntimeException
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
