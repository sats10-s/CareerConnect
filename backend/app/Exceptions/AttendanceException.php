<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\AttendanceService for any attendance business
 * rule violation - assignment not eligible to receive new attendance
 * (not ACTIVE), attendance date outside the assignment's period or in
 * the future, duplicate attendance for the same date, status/time
 * consistency violations, an attempt to correct a field an ordinary
 * update must never touch. Always caught by the calling controller
 * (Api\Faculty\AttendanceController / Api\Admin\AttendanceController)
 * and turned into a {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\InternshipAssignmentException/
 * InterviewException/PlacementDriveException exactly.
 */
class AttendanceException extends RuntimeException
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
