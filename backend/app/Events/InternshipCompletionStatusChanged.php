<?php

namespace App\Events;

use App\Enums\InternshipCompletionStatus;
use App\Models\InternshipCompletion;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\InternshipCompletionService every time a
 * completion record's RECORD status changes (null -> DRAFT at
 * creation, DRAFT -> FINALIZED at finalize()). EXTENSION POINT, not a
 * feature - mirrors App\Events\InternshipDocumentStatusChanged/
 * InternshipAssignmentStatusChanged exactly. No listener is registered
 * yet - a full notification system is explicitly out of scope for this
 * increment.
 */
class InternshipCompletionStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly InternshipCompletion $completion,
        public readonly ?InternshipCompletionStatus $fromStatus,
        public readonly InternshipCompletionStatus $toStatus,
        public readonly ?User $changedBy,
    ) {}
}
