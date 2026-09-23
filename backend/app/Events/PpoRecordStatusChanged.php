<?php

namespace App\Events;

use App\Enums\PpoStatus;
use App\Models\PpoRecord;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\InternshipCompletionService every time a PPO
 * record's status changes (null -> OFFERED at creation, OFFERED ->
 * ACCEPTED/DECLINED when the student's response is recorded).
 * EXTENSION POINT, not a feature - mirrors
 * App\Events\InternshipCompletionStatusChanged exactly. No listener is
 * registered yet - a full notification system is explicitly out of
 * scope for this increment.
 */
class PpoRecordStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly PpoRecord $ppoRecord,
        public readonly ?PpoStatus $fromStatus,
        public readonly PpoStatus $toStatus,
        public readonly ?User $changedBy,
    ) {}
}
