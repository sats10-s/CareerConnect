<?php

namespace App\Events;

use App\Enums\InternshipDocumentStatus;
use App\Models\InternshipDocument;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\InternshipDocumentService every time a
 * document's status changes, including the initial null -> SUBMITTED
 * write on upload/resubmission. EXTENSION POINT, not a feature -
 * mirrors App\Events\InternshipAssignmentStatusChanged exactly. No
 * listener is registered yet - notifications are explicitly out of
 * scope for this increment (see the module spec's "Do not implement a
 * full notification system here").
 */
class InternshipDocumentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly InternshipDocument $document,
        public readonly ?InternshipDocumentStatus $fromStatus,
        public readonly InternshipDocumentStatus $toStatus,
        public readonly ?User $changedBy,
    ) {}
}
