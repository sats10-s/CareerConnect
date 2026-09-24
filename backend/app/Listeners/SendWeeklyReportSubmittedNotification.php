<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\WeeklyReportSubmitted;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the coordinating faculty member when a student submits a
 * weekly progress report for review - the module spec's "Faculty:
 * weekly report submitted". Registered against
 * App\Events\WeeklyReportSubmitted in
 * App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendWeeklyReportSubmittedNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(WeeklyReportSubmitted $event): void
    {
        try {
            $recipient = $event->report->internshipAssignment?->faculty?->user;

            if ($recipient === null) {
                return;
            }

            $this->notificationService->create(
                $recipient,
                NotificationType::WEEKLY_REPORT_SUBMITTED,
                'Weekly report submitted',
                sprintf('A weekly progress report (week %d) was submitted for your review.', $event->report->week_number),
                ['weekly_progress_report_id' => $event->report->id],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to create weekly report submitted notification.', [
                'exception' => $e->getMessage(),
                'weekly_progress_report_id' => $event->report->id ?? null,
            ]);
        }
    }
}
