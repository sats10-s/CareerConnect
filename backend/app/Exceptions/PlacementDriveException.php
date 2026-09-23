<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\PlacementDriveService for any placement-drive
 * business rule violation - invalid status transition, publishing
 * with no opportunities attached, attaching an opportunity from the
 * wrong company, a deadline after the scheduled date, and so on.
 * Always caught by the calling controller (Api\Admin\PlacementDriveController)
 * and turned into a {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\ApplicationException /
 * App\Exceptions\InterviewException exactly.
 */
class PlacementDriveException extends RuntimeException
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
