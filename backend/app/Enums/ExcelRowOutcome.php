<?php

namespace App\Enums;

/**
 * The per-row result of matching + validation for one Excel row - see
 * App\Services\ExcelValidationService and App\Models\ExcelImportRow.
 *
 *  VALID   - fully matched, transition/update is legal; eligible for
 *            confirmation.
 *  WARNING - matched, but something needs human review before
 *            confirming (module spec section 4's example: roll number
 *            matched but the Excel name differs significantly from the
 *            database name) - STILL eligible for confirmation, because
 *            by the time an admin clicks "Confirm Import" they have
 *            already seen this row's warning in the preview screen
 *            (module spec section 9/10); WARNING is a "look before you
 *            click", not a silent block.
 *  ERROR   - no legal update is possible for this row (student not
 *            found, ambiguous match, no application, invalid
 *            transition, unknown status value, ...) - NEVER eligible
 *            for confirmation (module spec section 9: "Only valid rows
 *            may be confirmed" - read here as "only non-ERROR rows").
 */
enum ExcelRowOutcome: string
{
    case VALID = 'VALID';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';

    public function isConfirmable(): bool
    {
        return $this !== self::ERROR;
    }
}
