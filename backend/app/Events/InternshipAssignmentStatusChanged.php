<?php

namespace App\Events;

use App\Enums\InternshipAssignmentStatus;
use App\Models\InternshipAssignment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\InternshipAssignmentService every time an
 * assignment's status changes, including the initial null -> ASSIGNED
 * write. EXTENSION POINT, not a feature - mirrors
 * App\Events\InterviewStatusChanged/PlacementDriveStatusChanged
 * exactly. No listener is registered yet (notifications are out of
 * scope for this increment - see the module spec's "do not implement"
 * list).
 */
class InternshipAssignmentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly InternshipAssignment $internshipAssignment,
        public readonly ?InternshipAssignmentStatus $fromStatus,
        public readonly InternshipAssignmentStatus $toStatus,
        public readonly ?User $changedBy,
    ) {}
}
