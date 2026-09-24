<?php

namespace App\Listeners;

use App\Enums\InterviewStatus;
use App\Enums\NotificationType;
use App\Events\InterviewStatusChanged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the interviewed student when their interview is scheduled
 * (fromStatus === null && toStatus === SCHEDULED), rescheduled
 * (toStatus === RESCHEDULED), or completed (toStatus === COMPLETED).
 * CANCELLED is deliberately not notified - there is no
 * INTERVIEW_CANCELLED case in App\Enums\NotificationType (the module
 * spec's own notification-type list does not include one), so this
 * listener simply does nothing for that transition rather than
 * inventing a type the spec never asked for. Registered against
 * App\Events\InterviewStatusChanged in
 * App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendInterviewStatusNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(InterviewStatusChanged $event): void
    {
        try {
            $type = match (true) {
                $event->fromStatus === null && $event->toStatus === InterviewStatus::SCHEDULED => NotificationType::INTERVIEW_SCHEDULED,
                $event->toStatus === InterviewStatus::RESCHEDULED => NotificationType::INTERVIEW_RESCHEDULED,
                $event->toStatus === InterviewStatus::COMPLETED => NotificationType::INTERVIEW_COMPLETED,
                default => null,
            };

            if ($type === null) {
                return;
            }

            $recipient = $event->interview->application?->student?->user;

            if ($recipient === null) {
                return;
            }

            $this->notificationService->create(
                $recipient,
                $type,
                $type->label(),
                sprintf('Your interview (round %d) is now %s.', $event->interview->round, $event->toStatus->value),
                [
                    'interview_id' => $event->interview->id,
                    'application_id' => $event->interview->application_id,
                    'status' => $event->toStatus->value,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to create interview status notification.', [
                'exception' => $e->getMessage(),
                'interview_id' => $event->interview->id ?? null,
            ]);
        }
    }
}
