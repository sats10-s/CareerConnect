<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\InternshipCompletionService for any
 * internship-completion business rule violation - creating a second
 * completion record for an assignment that already has one, creating/
 * updating/finalizing one for an assignment that is not ACTIVE/
 * COMPLETED, updating or finalizing an already-FINALIZED record, or an
 * invalid record-status transition. Always caught by the calling
 * controller (Api\Faculty\InternshipCompletionController) and turned
 * into a {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\InternshipDocumentException/
 * WeeklyProgressReportException exactly.
 */
class InternshipCompletionException extends RuntimeException
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
