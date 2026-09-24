<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\WeeklyReportApproved;
use App\Events\WeeklyReportChangesRequested;
use App\Models\WeeklyProgressReport;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the reporting student when their weekly progress report is
 * reviewed by faculty - either approved or sent back with changes
 * requested - the module spec's "Student: weekly report reviewed"
 * (both outcomes count as "reviewed"; the notification's `data.outcome`
 * field distinguishes which one happened). Two entry points
 * (handleApproved/handleChangesRequested) share one private notify()
 * because WeeklyReportApproved/WeeklyReportChangesRequested are two
 * separate event classes - see App\Services\WeeklyProgressReportService,
 * which dispatches one or the other, never both, from its own
 * approve()/requestChanges() methods. Both are registered against this
 * class's two methods in App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendWeeklyReportReviewedNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handleApproved(WeeklyReportApproved $event): void
    {
        $this->notify($event->report, 'approved');
    }

    public function handleChangesRequested(WeeklyReportChangesRequested $event): void
    {
        $this->notify($event->report, 'changes requested');
    }

    private function notify(WeeklyProgressReport $report, string $outcome): void
    {
        try {
            $recipient = $report->internshipAssignment?->student?->user;

            if ($recipient === null) {
                return;
            }

            $this->notificationService->create(
                $recipient,
                NotificationType::WEEKLY_REPORT_REVIEWED,
                'Weekly report reviewed',
                sprintf('Your weekly progress report (week %d) was reviewed: %s.', $report->week_number, $outcome),
                ['weekly_progress_report_id' => $report->id, 'outcome' => $outcome],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to create weekly report reviewed notification.', [
                'exception' => $e->getMessage(),
                'weekly_progress_report_id' => $report->id ?? null,
            ]);
        }
    }
}
