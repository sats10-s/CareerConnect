<?php

namespace App\Listeners;

use App\Enums\AttendanceStatus;
use App\Enums\NotificationType;
use App\Events\AttendanceMarked;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alerts the coordinating faculty member whenever a student is marked
 * (or corrected to) ABSENT - the module spec's "Faculty: attendance-
 * related alerts where the existing system generates such an event".
 * No such event existed before this module (see
 * App\Events\AttendanceMarked's own docblock for the minimal addition
 * that created one); PRESENT/LEAVE never notify. Registered against
 * App\Events\AttendanceMarked in
 * App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendAttendanceAlertNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(AttendanceMarked $event): void
    {
        try {
            if ($event->newStatus !== AttendanceStatus::ABSENT) {
                return;
            }

            $assignment = $event->attendance->internshipAssignment;
            $recipient = $assignment?->faculty?->user;

            if ($recipient === null) {
                return;
            }

            $studentName = $assignment->student?->user?->name ?? 'A student';

            $this->notificationService->create(
                $recipient,
                NotificationType::ATTENDANCE_ALERT,
                'Attendance alert',
                sprintf('%s was marked absent on %s.', $studentName, $event->attendance->attendance_date?->toDateString()),
                [
                    'attendance_id' => $event->attendance->id,
                    'internship_assignment_id' => $assignment->id,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Failed to create attendance alert notification.', [
                'exception' => $e->getMessage(),
                'attendance_id' => $event->attendance->id ?? null,
            ]);
        }
    }
}
