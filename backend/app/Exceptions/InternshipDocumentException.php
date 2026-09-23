<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\InternshipDocumentService for any internship-
 * document business rule violation - an invalid lifecycle transition,
 * a resubmission attempted outside CHANGES_REQUESTED, an attempt to
 * modify an APPROVED document, a review action missing required
 * remarks, or a storage-layer failure while uploading/resubmitting.
 * Always caught by the calling controller (Api\Student\
 * InternshipDocumentController / Api\Faculty\InternshipDocumentController)
 * and turned into a {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\WeeklyProgressReportException/
 * AttendanceException exactly.
 */
class InternshipDocumentException extends RuntimeException
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
