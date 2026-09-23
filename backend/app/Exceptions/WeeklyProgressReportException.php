<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\WeeklyProgressReportService for any weekly-
 * report business rule violation - assignment not eligible to receive
 * a new/updated report, invalid week number/dates, a week outside the
 * assignment's internship period, a future week, duplicate week,
 * status/content consistency violations, an invalid lifecycle
 * transition. Always caught by the calling controller
 * (Api\Student\WeeklyProgressReportController /
 * Api\Faculty\WeeklyProgressReportController) and turned into a
 * {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\AttendanceException/
 * InternshipAssignmentException exactly.
 */
class WeeklyProgressReportException extends RuntimeException
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
