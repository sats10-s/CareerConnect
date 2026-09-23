<?php

namespace App\Events;

use App\Models\User;
use App\Models\WeeklyProgressReport;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\WeeklyProgressReportService::submit(). EXTENSION
 * POINT, not a feature - mirrors App\Events\InternshipAssignmentStatusChanged
 * exactly. No listener is registered yet (notifications are explicitly
 * out of scope for this increment - see the module spec's "do not
 * build email/push infrastructure yet").
 */
class WeeklyReportSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly WeeklyProgressReport $report,
        public readonly User $submittedBy,
    ) {}
}
