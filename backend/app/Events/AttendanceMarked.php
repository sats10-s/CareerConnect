<?php

namespace App\Events;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Services\AttendanceService every time an attendance
 * record is marked or corrected, including the initial null ->
 * (PRESENT|ABSENT|LEAVE) write. Mirrors every other lifecycle event in
 * this codebase (App\Events\ApplicationStatusChanged, etc.) exactly,
 * even though `attendance.status` is not itself a multi-step lifecycle
 * the way Application/Interview/... status is - AttendanceService had
 * no event of its own at all before the Notifications & Announcements
 * module (see that service's docblock history): this is the smallest
 * possible addition to it (two `event(new AttendanceMarked(...))`
 * calls, nothing else) needed to give
 * App\Listeners\SendAttendanceAlertNotification a real hook for the
 * "Faculty: attendance-related alerts" requirement, rather than
 * teaching AttendanceService itself anything about notifications.
 */
class AttendanceMarked
{
    use Dispatchable;

    public function __construct(
        public readonly Attendance $attendance,
        public readonly ?AttendanceStatus $oldStatus,
        public readonly AttendanceStatus $newStatus,
        public readonly ?User $changedBy,
    ) {}
}
