<?php

namespace App\Events;

use App\Enums\InterviewStatus;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\InterviewService every time an interview's
 * status changes, including the initial null -> SCHEDULED write.
 * EXTENSION POINT, not a feature - mirrors
 * App\Events\ApplicationStatusChanged exactly; see its docblock. No
 * listener is registered yet (notifications are out of scope for this
 * increment).
 */
class InterviewStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Interview $interview,
        public readonly ?InterviewStatus $fromStatus,
        public readonly InterviewStatus $toStatus,
        public readonly ?User $changedBy,
    ) {}
}
