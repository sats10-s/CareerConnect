<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\ApplicationStatusChanged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the applying student when their application is first
 * submitted (APPLICATION_SUBMITTED, i.e. fromStatus === null) or
 * subsequently moves to any other status (APPLICATION_STATUS_CHANGED),
 * including the student's own withdrawal. Registered against
 * App\Events\ApplicationStatusChanged in
 * App\Providers\AppServiceProvider::boot().
 *
 * Every listener in this module wraps its body in try/catch(Throwable)
 * and only ever logs a failure - never lets it propagate. This is not
 * defensive boilerplate: App\Services\ApplicationService (like every
 * other domain service) dispatches this event from INSIDE its own
 * DB::transaction(). Laravel's default sync event dispatch runs
 * listeners synchronously and immediately, so an uncaught exception
 * here would bubble up through event()/DB::transaction() and roll back
 * the application write and status-history row this listener is
 * reacting to - a notification failure must never be able to break the
 * domain action that triggered it.
 */
class SendApplicationStatusNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(ApplicationStatusChanged $event): void
    {
        try {
            $recipient = $event->application->student?->user;

            if ($recipient === null) {
                return;
            }

            if ($event->fromStatus === null) {
                $this->notificationService->create(
                    $recipient,
                    NotificationType::APPLICATION_SUBMITTED,
                    'Application submitted',
                    'Your application has been submitted successfully.',
                    ['application_id' => $event->application->id, 'status' => $event->toStatus->value],
                );

                return;
            }

            $this->notificationService->create(
                $recipient,
                NotificationType::APPLICATION_STATUS_CHANGED,
                'Application status updated',
                sprintf('Your application status changed to %s.', $event->toStatus->value),
                [
                    'application_id' => $event->application->id,
                    'from_status' => $event->fromStatus->value,
                    'to_status' => $event->toStatus->value,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to create application status notification.', [
                'exception' => $e->getMessage(),
                'application_id' => $event->application->id ?? null,
            ]);
        }
    }
}
