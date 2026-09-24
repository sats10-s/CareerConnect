<?php

namespace App\Providers;

use App\Events\ApplicationStatusChanged;
use App\Events\AttendanceMarked;
use App\Events\InternshipAssignmentStatusChanged;
use App\Events\InternshipCompletionStatusChanged;
use App\Events\InternshipDocumentStatusChanged;
use App\Events\InterviewStatusChanged;
use App\Events\PpoRecordStatusChanged;
use App\Events\WeeklyReportApproved;
use App\Events\WeeklyReportChangesRequested;
use App\Events\WeeklyReportSubmitted;
use App\Listeners\SendApplicationStatusNotification;
use App\Listeners\SendAttendanceAlertNotification;
use App\Listeners\SendInternshipAssignmentNotification;
use App\Listeners\SendInternshipCompletionNotification;
use App\Listeners\SendInternshipDocumentNotification;
use App\Listeners\SendInterviewStatusNotification;
use App\Listeners\SendPpoRecordNotification;
use App\Listeners\SendWeeklyReportReviewedNotification;
use App\Listeners\SendWeeklyReportSubmittedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    /**
     * Notifications & Announcements module - wires every existing
     * lifecycle event (each one was already a real, working "EXTENSION
     * POINT, not a feature" - see each App\Events\* class's own
     * docblock) to the listener that turns it into an in-app
     * notification. Explicit Event::listen() calls, not
     * #[AsEventListener] attribute auto-discovery: this codebase had
     * NO app/Listeners directory and NO event registration of any kind
     * before this module, so there was no existing precedent either
     * way - explicit registration here is the more debuggable, more
     * easily-verified-by-reading choice for a codebase's very first
     * listeners. See each listener class's own docblock for exactly
     * which transition(s) of its event it reacts to and who it
     * notifies.
     */
    public function boot(): void
    {
        Event::listen(ApplicationStatusChanged::class, SendApplicationStatusNotification::class);
        Event::listen(InterviewStatusChanged::class, SendInterviewStatusNotification::class);
        Event::listen(InternshipAssignmentStatusChanged::class, SendInternshipAssignmentNotification::class);
        Event::listen(AttendanceMarked::class, SendAttendanceAlertNotification::class);
        Event::listen(WeeklyReportSubmitted::class, SendWeeklyReportSubmittedNotification::class);
        Event::listen(WeeklyReportApproved::class, [SendWeeklyReportReviewedNotification::class, 'handleApproved']);
        Event::listen(WeeklyReportChangesRequested::class, [SendWeeklyReportReviewedNotification::class, 'handleChangesRequested']);
        Event::listen(InternshipDocumentStatusChanged::class, SendInternshipDocumentNotification::class);
        Event::listen(InternshipCompletionStatusChanged::class, SendInternshipCompletionNotification::class);
        Event::listen(PpoRecordStatusChanged::class, SendPpoRecordNotification::class);
    }
}
