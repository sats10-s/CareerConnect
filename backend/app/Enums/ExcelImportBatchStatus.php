<?php

namespace App\Enums;

/**
 * Excel Sync Center batch lifecycle - exactly the 6 values the module
 * spec's section 11 lists. See App\Services\ExcelImportService for the
 * only writer of a new status:
 *
 *  UPLOADED               - file stored, not parsed yet (never actually
 *                            observed by a caller - parsing happens
 *                            synchronously in the same request as
 *                            upload, so a batch moves to VALIDATED
 *                            before the upload response is even sent;
 *                            kept as the initial in-transaction value
 *                            for clarity/debuggability).
 *  VALIDATED               - every row parsed, matched, and validated;
 *                            counts (total/matched/valid/warning/error)
 *                            are final for this batch.
 *  READY_FOR_CONFIRMATION  - set immediately after VALIDATED once at
 *                            least one VALID or WARNING row exists -
 *                            the only status confirm() will act on.
 *  CONFIRMED               - all eligible rows processed inside one
 *                            transaction; excel_import_changes rows
 *                            exist for every field actually changed.
 *  FAILED                  - a critical failure during parse or
 *                            confirm (see excel_import_batches.error_message).
 *  ROLLED_BACK             - every change in this CONFIRMED batch was
 *                            successfully reverted with zero conflicts
 *                            (see ExcelImportService::rollback()'s
 *                            docblock for why a partially-conflicted
 *                            rollback deliberately does NOT flip to
 *                            this status).
 */
enum ExcelImportBatchStatus: string
{
    case UPLOADED = 'UPLOADED';
    case VALIDATED = 'VALIDATED';
    case READY_FOR_CONFIRMATION = 'READY_FOR_CONFIRMATION';
    case CONFIRMED = 'CONFIRMED';
    case FAILED = 'FAILED';
    case ROLLED_BACK = 'ROLLED_BACK';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
