<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\ExcelImportService/ExcelParsingService for any
 * Excel Sync Center business-rule rejection - an invalid file, a batch
 * in the wrong status for the requested action, a confirm attempt on a
 * batch with zero confirmable rows, and so on. Always caught by the
 * calling controller (Api\Admin\ExcelImportController) and turned into
 * a {success:false, message, errors} response via
 * FormatsApiResponse::failure() - mirrors every other *Exception class
 * in this codebase exactly.
 */
class ExcelImportException extends RuntimeException
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
