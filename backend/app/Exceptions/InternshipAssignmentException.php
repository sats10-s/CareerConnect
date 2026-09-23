<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\InternshipAssignmentService for any
 * assignment-lifecycle business rule violation - application not
 * SELECTED, application not for an internship, invalid/inactive
 * faculty, duplicate assignment, an invalid status transition, a
 * reassignment attempted on a terminal assignment. Always caught by
 * the calling controller (Api\Admin\InternshipAssignmentController)
 * and turned into a {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\InterviewException/
 * PlacementDriveException exactly.
 */
class InternshipAssignmentException extends RuntimeException
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
