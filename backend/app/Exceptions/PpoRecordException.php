<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\InternshipCompletionService for any PPO
 * record business rule violation - creating a second PPO record for an
 * assignment that already has one, creating/updating one for an
 * assignment that is not ACTIVE/COMPLETED, updating an already-terminal
 * (ACCEPTED/DECLINED) record, or an invalid status transition. Always
 * caught by the calling controller
 * (Api\Faculty\PpoRecordController) and turned into a
 * {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\InternshipCompletionException
 * exactly - kept as its own class (rather than reusing
 * InternshipCompletionException for both resources) since ppo_records
 * and internship_completions are two distinct tables/resources within
 * this module, matching this codebase's one-exception-per-primary-model
 * convention (AttendanceException, WeeklyProgressReportException,
 * InternshipDocumentException).
 */
class PpoRecordException extends RuntimeException
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
