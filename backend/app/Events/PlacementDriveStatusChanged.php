<?php

namespace App\Events;

use App\Enums\PlacementDriveStatus;
use App\Models\PlacementDrive;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\PlacementDriveService every time a drive's
 * status changes, including the initial null -> DRAFT write. EXTENSION
 * POINT, not a feature - mirrors App\Events\ApplicationStatusChanged /
 * App\Events\InterviewStatusChanged exactly; see their docblocks. No
 * listener is registered yet (notifications are out of scope for this
 * increment).
 */
class PlacementDriveStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly PlacementDrive $placementDrive,
        public readonly ?PlacementDriveStatus $fromStatus,
        public readonly PlacementDriveStatus $toStatus,
        public readonly ?User $changedBy,
    ) {}
}
