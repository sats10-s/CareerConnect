<?php

namespace App\Listeners;

use App\Enums\InternshipAssignmentStatus;
use App\Enums\NotificationType;
use App\Events\InternshipAssignmentStatusChanged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies BOTH the assigned student and the coordinating faculty
 * member the first time an internship assignment is created
 * (fromStatus === null && toStatus === ASSIGNED) - matching the module
 * spec's own list, which names "internship assigned" under both the
 * Student and Faculty sections. Later transitions on the SAME
 * assignment (ACTIVE/COMPLETED/CANCELLED) are not notified here - none
 * of those has a corresponding case in App\Enums\NotificationType
 * (INTERNSHIP_COMPLETED is produced by
 * App\Listeners\SendInternshipCompletionNotification instead, from the
 * separate InternshipCompletion record's own FINALIZED transition, not
 * from the assignment's own COMPLETED status - see that listener's
 * docblock for why). Registered against
 * App\Events\InternshipAssignmentStatusChanged in
 * App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendInternshipAssignmentNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(InternshipAssignmentStatusChanged $event): void
    {
        try {
            if ($event->fromStatus !== null || $event->toStatus !== InternshipAssignmentStatus::ASSIGNED) {
                return;
            }

            $assignment = $event->internshipAssignment;
            $recipients = array_filter([$assignment->student?->user, $assignment->faculty?->user]);

            foreach ($recipients as $recipient) {
                $this->notificationService->create(
                    $recipient,
                    NotificationType::INTERNSHIP_ASSIGNED,
                    'Internship assignment created',
                    'A new internship assignment has been created.',
                    ['internship_assignment_id' => $assignment->id],
                );
            }
        } catch (Throwable $e) {
            Log::warning('Failed to create internship assignment notification.', [
                'exception' => $e->getMessage(),
                'internship_assignment_id' => $event->internshipAssignment->id ?? null,
            ]);
        }
    }
}
