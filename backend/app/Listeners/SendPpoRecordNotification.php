<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\PpoRecordStatusChanged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the student on every PPO record status change - the
 * initial OFFERED creation and the terminal ACCEPTED/DECLINED response
 * alike (all three are, literally, the PPO being "updated") - the
 * module spec's "Student: PPO updated". Registered against
 * App\Events\PpoRecordStatusChanged in
 * App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendPpoRecordNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(PpoRecordStatusChanged $event): void
    {
        try {
            $recipient = $event->ppoRecord->internshipAssignment?->student?->user;

            if ($recipient === null) {
                return;
            }

            $this->notificationService->create(
                $recipient,
                NotificationType::PPO_UPDATED,
                'PPO updated',
                sprintf('Your Pre-Placement Offer status is now %s.', $event->toStatus->value),
                ['ppo_record_id' => $event->ppoRecord->id, 'status' => $event->toStatus->value],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to create PPO record notification.', [
                'exception' => $e->getMessage(),
                'ppo_record_id' => $event->ppoRecord->id ?? null,
            ]);
        }
    }
}
