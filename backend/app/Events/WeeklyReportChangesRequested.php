<?php

namespace App\Events;

use App\Models\User;
use App\Models\WeeklyProgressReport;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\WeeklyProgressReportService::requestChanges().
 * EXTENSION POINT, not a feature - see
 * App\Events\WeeklyReportSubmitted's docblock.
 */
class WeeklyReportChangesRequested
{
    use Dispatchable;

    public function __construct(
        public readonly WeeklyProgressReport $report,
        public readonly User $requestedBy,
        public readonly ?string $remarks,
    ) {}
}
