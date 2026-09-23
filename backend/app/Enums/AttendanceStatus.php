<?php

namespace App\Enums;

/**
 * The three possible marks for one attendance record - see
 * App\Models\Attendance and App\Services\AttendanceService (the only
 * writer of a new status). ALL-CAPS backed enum, matching this
 * schema's newer convention (ApplicationStatus/InterviewStatus/
 * PlacementDriveStatus/InternshipAssignmentStatus) - never an
 * arbitrary free-text status column.
 */
enum AttendanceStatus: string
{
    case PRESENT = 'PRESENT';
    case ABSENT = 'ABSENT';
    case LEAVE = 'LEAVE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /**
     * Only PRESENT may carry check-in/check-out times (and even then
     * they remain optional - a college that only tracks daily
     * attendance is never forced to enter them). ABSENT/LEAVE must
     * never carry either time - see StoreAttendanceRequest/
     * UpdateAttendanceRequest and AttendanceService, both of which
     * enforce this via this single method rather than duplicating the
     * status check in three places.
     */
    public function allowsTimes(): bool
    {
        return $this === self::PRESENT;
    }
}
