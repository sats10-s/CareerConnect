<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\AnnouncementService for any announcement-
 * lifecycle business rule violation - editing a non-draft, an invalid
 * status transition (e.g. publishing an already-published or archived
 * announcement). Always caught by the calling controller
 * (Api\Admin\AnnouncementController) and turned into a
 * {success:false, message, errors} response via
 * FormatsApiResponse::failure() - never allowed to bubble up as an
 * unhandled 500. Mirrors App\Exceptions\ApplicationException exactly.
 */
class AnnouncementException extends RuntimeException
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
