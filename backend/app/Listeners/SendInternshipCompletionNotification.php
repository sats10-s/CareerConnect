<?php

namespace App\Listeners;

use App\Enums\InternshipCompletionStatus;
use App\Enums\NotificationType;
use App\Events\InternshipCompletionStatusChanged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the student when their internship completion record is
 * FINALIZED by faculty - the module spec's "Student: internship
 * completed". Only fires on DRAFT -> FINALIZED, never on the record's
 * initial null -> DRAFT creation (App\Services\InternshipCompletionService::createCompletion()) -
 * a still-editable draft completion is not yet a finished fact worth
 * notifying about. This is deliberately NOT hooked to
 * App\Events\InternshipAssignmentStatusChanged's own toStatus ===
 * COMPLETED transition - that event fires on the ASSIGNMENT's status,
 * a separate record from the InternshipCompletion this module's
 * INTERNSHIP_COMPLETED type is about (see
 * App\Listeners\SendInternshipAssignmentNotification's docblock for
 * the same reasoning from the other side). Registered against
 * App\Events\InternshipCompletionStatusChanged in
 * App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendInternshipCompletionNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(InternshipCompletionStatusChanged $event): void
    {
        try {
            if ($event->toStatus !== InternshipCompletionStatus::FINALIZED) {
                return;
            }

            $recipient = $event->completion->internshipAssignment?->student?->user;

            if ($recipient === null) {
                return;
            }

            $this->notificationService->create(
                $recipient,
                NotificationType::INTERNSHIP_COMPLETED,
                'Internship completed',
                'Your internship completion has been finalized.',
                [
                    'internship_completion_id' => $event->completion->id,
                    'outcome' => $event->completion->outcome?->value,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to create internship completion notification.', [
                'exception' => $e->getMessage(),
                'internship_completion_id' => $event->completion->id ?? null,
            ]);
        }
    }
}
