<?php

namespace App\Enums;

/**
 * Every kind of in-app notification the Notifications & Announcements
 * module can create - see App\Models\Notification and
 * App\Services\NotificationService (the only writer). ALL-CAPS backed
 * enum, matching this schema's existing convention (ApplicationStatus/
 * InterviewStatus/AttendanceStatus/...) - never an arbitrary free-text
 * type column.
 *
 * Each domain-lifecycle case below is produced by exactly one listener
 * in app/Listeners, registered against the matching existing
 * App\Events\* class in App\Providers\AppServiceProvider::boot() - see
 * each listener's own docblock for which event/transition produces it.
 * ANNOUNCEMENT is produced by App\Services\AnnouncementService::publish()
 * fanning out to the announcement's audience. SYSTEM is reserved for
 * any future direct/manual notification (e.g. an admin action with no
 * dedicated lifecycle event of its own) - nothing in this increment
 * creates one, but NotificationService::create() accepts it like any
 * other case so it is never a dead value.
 */
enum NotificationType: string
{
    case APPLICATION_SUBMITTED = 'APPLICATION_SUBMITTED';
    case APPLICATION_STATUS_CHANGED = 'APPLICATION_STATUS_CHANGED';
    case INTERVIEW_SCHEDULED = 'INTERVIEW_SCHEDULED';
    case INTERVIEW_RESCHEDULED = 'INTERVIEW_RESCHEDULED';
    case INTERVIEW_COMPLETED = 'INTERVIEW_COMPLETED';
    case INTERNSHIP_ASSIGNED = 'INTERNSHIP_ASSIGNED';
    case ATTENDANCE_ALERT = 'ATTENDANCE_ALERT';
    case WEEKLY_REPORT_SUBMITTED = 'WEEKLY_REPORT_SUBMITTED';
    case WEEKLY_REPORT_REVIEWED = 'WEEKLY_REPORT_REVIEWED';
    case DOCUMENT_SUBMITTED = 'DOCUMENT_SUBMITTED';
    case DOCUMENT_REVIEWED = 'DOCUMENT_REVIEWED';
    case INTERNSHIP_COMPLETED = 'INTERNSHIP_COMPLETED';
    case PPO_UPDATED = 'PPO_UPDATED';
    case COMPANY_STATUS_CHANGED = 'COMPANY_STATUS_CHANGED';
    case COMPANY_POSTING_SUBMITTED = 'COMPANY_POSTING_SUBMITTED';
    case COMPANY_POSTING_PUBLISHED = 'COMPANY_POSTING_PUBLISHED';
    case ANNOUNCEMENT = 'ANNOUNCEMENT';
    case SYSTEM = 'SYSTEM';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::APPLICATION_SUBMITTED => 'Application Submitted',
            self::APPLICATION_STATUS_CHANGED => 'Application Status Changed',
            self::INTERVIEW_SCHEDULED => 'Interview Scheduled',
            self::INTERVIEW_RESCHEDULED => 'Interview Rescheduled',
            self::INTERVIEW_COMPLETED => 'Interview Completed',
            self::INTERNSHIP_ASSIGNED => 'Internship Assigned',
            self::ATTENDANCE_ALERT => 'Attendance Alert',
            self::WEEKLY_REPORT_SUBMITTED => 'Weekly Report Submitted',
            self::WEEKLY_REPORT_REVIEWED => 'Weekly Report Reviewed',
            self::DOCUMENT_SUBMITTED => 'Document Submitted',
            self::DOCUMENT_REVIEWED => 'Document Reviewed',
            self::INTERNSHIP_COMPLETED => 'Internship Completed',
            self::PPO_UPDATED => 'PPO Updated',
            self::COMPANY_STATUS_CHANGED => 'Company Status Changed',
            self::COMPANY_POSTING_SUBMITTED => 'Company Posting Submitted',
            self::COMPANY_POSTING_PUBLISHED => 'Company Posting Published',
            self::ANNOUNCEMENT => 'Announcement',
            self::SYSTEM => 'System',
        };
    }
}
