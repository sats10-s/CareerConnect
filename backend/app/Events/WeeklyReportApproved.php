<?php

namespace App\Events;

use App\Models\User;
use App\Models\WeeklyProgressReport;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\WeeklyProgressReportService::approve(). EXTENSION
 * POINT, not a feature - see App\Events\WeeklyReportSubmitted's docblock.
 */
class WeeklyReportApproved
{
    use Dispatchable;

    public function __construct(
        public readonly WeeklyProgressReport $report,
        public readonly User $approvedBy,
    ) {}
}
