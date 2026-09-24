<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceStatus;
use App\Enums\InternshipAssignmentStatus;
use App\Enums\InterviewStatus;
use App\Enums\PpoStatus;

/**
 * The "Validate" stage of the module spec's section 1 pipeline - pure,
 * side-effect-free normalization and legality checks. Never touches
 * MySQL and never decides what a valid MATCH looks like (that's
 * App\Services\ExcelMatchingService); this class only answers two
 * questions: "what canonical status does this Excel text mean?" and
 * "is moving from status A to status B allowed?" - the latter always
 * delegated to the SAME enum methods (canTransitionTo()) the admin
 * portal's own controllers already use, per the module spec's
 * non-negotiable section 8 requirement that Excel behave exactly like
 * a manual admin status change.
 *
 * Module spec section 7 - normalization is: trim whitespace,
 * case-insensitive, collapse internal spaces, KNOWN ALIASES ONLY.
 * "Unknown values must produce an error... Never guess" - every
 * normalize*() method below returns null for anything not in its
 * fixed alias table, and callers (App\Services\ExcelImportService)
 * always turn that null into an ERROR row, never a best-guess status.
 */
class ExcelValidationService
{
    /**
     * @var array<string, string>
     */
    private const APPLICATION_STATUS_ALIASES = [
        'APPLIED' => 'APPLIED',
        'UNDER REVIEW' => 'UNDER_REVIEW',
        'UNDER_REVIEW' => 'UNDER_REVIEW',
        'IN REVIEW' => 'UNDER_REVIEW',
        'SHORTLISTED' => 'SHORTLISTED',
        'SHORTLIST' => 'SHORTLISTED',
        'SHORT LIST' => 'SHORTLISTED',
        'SHORT LISTED' => 'SHORTLISTED',
        'ASSESSMENT' => 'ASSESSMENT',
        'ASSESSMENT ROUND' => 'ASSESSMENT',
        'INTERVIEW' => 'INTERVIEW',
        'INTERVIEWING' => 'INTERVIEW',
        'SELECTED' => 'SELECTED',
        'SELECT' => 'SELECTED',
        'OFFERED' => 'SELECTED',
        'REJECTED' => 'REJECTED',
        'REJECT' => 'REJECTED',
        'NOT SELECTED' => 'REJECTED',
        'WITHDRAWN' => 'WITHDRAWN',
        'WITHDRAW' => 'WITHDRAWN',
    ];

    /**
     * @var array<string, string>
     */
    private const INTERNSHIP_ASSIGNMENT_STATUS_ALIASES = [
        'ASSIGNED' => 'ASSIGNED',
        'ACTIVE' => 'ACTIVE',
        'ONGOING' => 'ACTIVE',
        'IN PROGRESS' => 'ACTIVE',
        'COMPLETED' => 'COMPLETED',
        'COMPLETE' => 'COMPLETED',
        'CANCELLED' => 'CANCELLED',
        'CANCELED' => 'CANCELLED',
    ];

    /**
     * @var array<string, string>
     */
    private const PPO_STATUS_ALIASES = [
        'OFFERED' => 'OFFERED',
        'ACCEPTED' => 'ACCEPTED',
        'ACCEPT' => 'ACCEPTED',
        'DECLINED' => 'DECLINED',
        'DECLINE' => 'DECLINED',
        'REJECTED' => 'DECLINED',
    ];

    /**
     * @var array<string, string>
     */
    private const INTERVIEW_STATUS_ALIASES = [
        'SCHEDULED' => 'SCHEDULED',
        'SCHEDULE' => 'SCHEDULED',
        'COMPLETED' => 'COMPLETED',
        'COMPLETE' => 'COMPLETED',
        'DONE' => 'COMPLETED',
        'CANCELLED' => 'CANCELLED',
        'CANCELED' => 'CANCELLED',
        'RESCHEDULED' => 'RESCHEDULED',
        'RESCHEDULE' => 'RESCHEDULED',
    ];

    /**
     * @var array<string, string>
     */
    private const ATTENDANCE_STATUS_ALIASES = [
        'PRESENT' => 'PRESENT',
        'P' => 'PRESENT',
        'ABSENT' => 'ABSENT',
        'A' => 'ABSENT',
        'LEAVE' => 'LEAVE',
        'L' => 'LEAVE',
        'ON LEAVE' => 'LEAVE',
    ];

    public function normalizeApplicationStatus(?string $raw): ?ApplicationStatus
    {
        $key = self::APPLICATION_STATUS_ALIASES[$this->key($raw)] ?? null;

        return $key !== null ? ApplicationStatus::from($key) : null;
    }

    public function normalizeInternshipAssignmentStatus(?string $raw): ?InternshipAssignmentStatus
    {
        $key = self::INTERNSHIP_ASSIGNMENT_STATUS_ALIASES[$this->key($raw)] ?? null;

        return $key !== null ? InternshipAssignmentStatus::from($key) : null;
    }

    public function normalizePpoStatus(?string $raw): ?PpoStatus
    {
        $key = self::PPO_STATUS_ALIASES[$this->key($raw)] ?? null;

        return $key !== null ? PpoStatus::from($key) : null;
    }

    public function normalizeInterviewStatus(?string $raw): ?InterviewStatus
    {
        $key = self::INTERVIEW_STATUS_ALIASES[$this->key($raw)] ?? null;

        return $key !== null ? InterviewStatus::from($key) : null;
    }

    public function normalizeAttendanceStatus(?string $raw): ?AttendanceStatus
    {
        $key = self::ATTENDANCE_STATUS_ALIASES[$this->key($raw)] ?? null;

        return $key !== null ? AttendanceStatus::from($key) : null;
    }

    /**
     * Module spec section 7 normalization: trim, case-insensitive,
     * collapse internal whitespace - identical treatment for every
     * status column across every import type.
     */
    private function key(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        $collapsed = preg_replace('/\s+/', ' ', trim($raw)) ?? '';

        return mb_strtoupper($collapsed);
    }
}
