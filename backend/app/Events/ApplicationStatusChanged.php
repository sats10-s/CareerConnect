<?php

namespace App\Events;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\ApplicationService every time an application's
 * status changes, including the initial null -> APPLIED write.
 *
 * EXTENSION POINT, not a feature: per the Application Lifecycle module
 * spec (section 11), the full notification module is explicitly out
 * of scope for this increment, and no listener is registered for this
 * event yet - nothing currently observes it, so dispatching it has no
 * side effects beyond this file. It exists as a real, working event
 * boundary (rather than a TODO comment) precisely so that a future
 * notification listener (e.g. "email the student when their
 * application is shortlisted") can be added later with ZERO changes
 * to ApplicationService's apply()/withdraw()/updateStatus() methods -
 * just a new listener class registered against this event.
 */
class ApplicationStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Application $application,
        public readonly ?ApplicationStatus $fromStatus,
        public readonly ApplicationStatus $toStatus,
        public readonly ?User $changedBy,
    ) {}
}
