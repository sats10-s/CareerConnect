<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\InterviewService for any interview-lifecycle
 * business rule violation - application not in an interview-eligible
 * status, opportunity no longer valid, an interview that can't be
 * rescheduled/completed/cancelled/edited because it's already
 * terminal. Always caught by the calling controller
 * (Api\Admin\InterviewController) and turned into a {success:false,
 * message, errors} response via FormatsApiResponse::failure() - never
 * allowed to bubble up as an unhandled 500. Mirrors
 * App\Exceptions\ApplicationException exactly.
 */
class InterviewException extends RuntimeException
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
