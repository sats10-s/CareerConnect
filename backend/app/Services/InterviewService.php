<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Enums\OpportunityStatus;
use App\Events\InterviewStatusChanged;
use App\Exceptions\InterviewException;
use App\Models\Application;
use App\Models\Interview;
use App\Models\InterviewStatusHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single place every interview-lifecycle write goes through:
 * creating an interview, rescheduling one, completing one, cancelling
 * one, or editing its non-scheduling fields. Each lifecycle method is
 * atomic and writes exactly one paired InterviewStatusHistory row per
 * status change - callers (Api\Admin\InterviewController) never touch
 * `interviews` or `interview_status_history` directly.
 *
 * Reuses App\Services\ApplicationService::updateStatus() for the
 * SHORTLISTED/ASSESSMENT -> INTERVIEW application transition that
 * accompanies interview creation, rather than writing to
 * applications/application_status_history itself - see create().
 *
 * scheduled_at handling: every incoming scheduled_at value is
 * normalized with Carbon::parse($value)->utc() before being persisted
 * - config('app.timezone') is 'UTC', and every other timestamp in this
 * schema (applied_at, created_at, ...) is implicitly stored the same
 * way, so this keeps scheduled_at consistent with the rest of the
 * backend rather than storing whatever local wall-clock digits a
 * client happened to submit. A caller may submit scheduled_at with an
 * explicit offset (e.g. "2026-10-01T15:30:00+05:30") or as a bare UTC
 * string ("2026-10-01T10:00:00") - Carbon::parse() treats a bare
 * string with no offset as being in PHP's default timezone, which
 * this application also sets to UTC (see config/app.php), so a bare
 * string is correctly treated as UTC, not silently misread as some
 * other zone.
 */
class InterviewService
{
    /**
     * Only these application statuses may receive a new interview -
     * see the module spec's create-interview rules. APPLIED and
     * UNDER_REVIEW are rejected (an interview shouldn't be scheduled
     * before the admin has at least shortlisted the candidate), and so
     * are SELECTED (nothing left to interview for) and REJECTED/
     * WITHDRAWN (application is over).
     */
    private const INTERVIEWABLE_APPLICATION_STATUSES = [
        ApplicationStatus::SHORTLISTED,
        ApplicationStatus::ASSESSMENT,
        ApplicationStatus::INTERVIEW,
    ];

    /**
     * An opportunity that was never published, or has been archived
     * outright, is not a valid target for scheduling an interview
     * against - PUBLISHED and CLOSED are both fine (closing an
     * opportunity to new applicants doesn't stop the existing pipeline
     * for students who already applied while it was open).
     */
    private const INVALID_OPPORTUNITY_STATUSES = [
        OpportunityStatus::DRAFT,
        OpportunityStatus::ARCHIVED,
    ];

    public function __construct(
        private readonly ApplicationService $applicationService,
    ) {}

    /**
     * @param array<string, mixed> $data validated StoreInterviewRequest data
     */
    public function create(Application $application, array $data, User $actingUser): Interview
    {
        if (! in_array($application->status, self::INTERVIEWABLE_APPLICATION_STATUSES, true)) {
            throw new InterviewException(sprintf(
                'An interview cannot be scheduled while the application is %s.',
                $application->status->value,
            ));
        }

        $opportunity = $application->applicable;

        if ($opportunity === null || in_array($opportunity->status, self::INVALID_OPPORTUNITY_STATUSES, true)) {
            throw new InterviewException('This application no longer belongs to a valid opportunity.');
        }

        $applicationStatusBeforeInterview = $application->status;

        return DB::transaction(function () use ($application, $data, $actingUser, $applicationStatusBeforeInterview) {
            $interview = Interview::create([
                'application_id' => $application->id,
                'round' => $data['round'],
                'title' => $data['title'] ?? null,
                'scheduled_at' => $this->normalizeScheduledAt($data['scheduled_at']),
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'mode' => $data['mode'],
                'meeting_url' => $data['meeting_url'] ?? null,
                'venue' => $data['venue'] ?? null,
                'interviewer_name' => $data['interviewer_name'] ?? null,
                'interviewer_email' => $data['interviewer_email'] ?? null,
                'panel_notes' => $data['panel_notes'] ?? null,
                'status' => InterviewStatus::SCHEDULED,
                'created_by' => $actingUser->id,
            ]);

            $this->recordHistory($interview, null, InterviewStatus::SCHEDULED, $actingUser, null);
            event(new InterviewStatusChanged($interview, null, InterviewStatus::SCHEDULED, $actingUser));

            // Reuses the existing, already-tested application transition
            // mechanism rather than writing to `applications` directly -
            // see App\Services\ApplicationService::updateStatus(). Only
            // fires when the application isn't already INTERVIEW, since
            // ApplicationStatus::INTERVIEW->canTransitionTo(INTERVIEW) is
            // false (it's not a "transition" to move to the status
            // you're already in).
            if (in_array($applicationStatusBeforeInterview, [ApplicationStatus::SHORTLISTED, ApplicationStatus::ASSESSMENT], true)) {
                $this->applicationService->updateStatus($application, ApplicationStatus::INTERVIEW, $actingUser);
            }

            return $interview;
        });
    }

    /**
     * Edits an interview's non-scheduling fields (title, round,
     * duration, mode, meeting_url, venue, interviewer_name/email,
     * panel_notes). Never touches `scheduled_at` or `status` - a
     * scheduled-time change is a reschedule() (see below), enforced at
     * the validation layer (UpdateInterviewRequest rejects a submitted
     * scheduled_at outright) as well as here, defensively.
     *
     * @param array<string, mixed> $data validated UpdateInterviewRequest data
     */
    public function update(Interview $interview, array $data, User $actingUser): Interview
    {
        if ($interview->status->isTerminal()) {
            throw new InterviewException('A completed or cancelled interview can no longer be edited.');
        }

        if (array_key_exists('scheduled_at', $data)) {
            throw new InterviewException('Changing the scheduled time must go through the reschedule endpoint.');
        }

        $updates = array_intersect_key($data, array_flip([
            'round', 'title', 'duration_minutes', 'mode',
            'meeting_url', 'venue', 'interviewer_name', 'interviewer_email', 'panel_notes',
        ]));
        $updates['updated_by'] = $actingUser->id;

        $interview->update($updates);

        return $interview;
    }

    /**
     * Reschedules an interview IN PLACE - the same row's scheduled_at
     * (and any other scheduling fields supplied) is updated and its
     * status becomes RESCHEDULED, rather than creating a second
     * interview row. See the interviews migration's docblock for why
     * this design was chosen over spawning a new record. The previous
     * scheduled time is never silently lost: it's recorded in the new
     * interview_status_history row's remarks alongside any admin-
     * supplied reason, so "what was it before, and why did it change"
     * is always reconstructable from the audit trail even though the
     * interview row itself only ever holds the current schedule.
     *
     * @param array<string, mixed> $data validated RescheduleInterviewRequest data
     */
    public function reschedule(Interview $interview, array $data, User $actingUser): Interview
    {
        if (! $interview->status->canTransitionTo(InterviewStatus::RESCHEDULED)) {
            throw new InterviewException('This interview can no longer be rescheduled.');
        }

        $previousScheduledAt = $interview->scheduled_at;

        return DB::transaction(function () use ($interview, $data, $actingUser, $previousScheduledAt) {
            $from = $interview->status;
            $newScheduledAt = $this->normalizeScheduledAt($data['scheduled_at']);

            $updates = ['scheduled_at' => $newScheduledAt, 'status' => InterviewStatus::RESCHEDULED, 'updated_by' => $actingUser->id];

            foreach (['duration_minutes', 'mode', 'meeting_url', 'venue'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            $interview->update($updates);

            $note = sprintf(
                'Rescheduled from %s to %s.',
                $previousScheduledAt?->toIso8601String() ?? 'an unset time',
                $newScheduledAt->toIso8601String(),
            );

            if (! empty($data['remarks'])) {
                $note .= ' '.$data['remarks'];
            }

            $this->recordHistory($interview, $from, InterviewStatus::RESCHEDULED, $actingUser, $note);
            event(new InterviewStatusChanged($interview, $from, InterviewStatus::RESCHEDULED, $actingUser));

            return $interview;
        });
    }

    public function complete(Interview $interview, User $actingUser, ?string $remarks = null): Interview
    {
        if (! $interview->status->canTransitionTo(InterviewStatus::COMPLETED)) {
            throw new InterviewException('Only a scheduled or rescheduled interview can be marked completed.');
        }

        return DB::transaction(function () use ($interview, $actingUser, $remarks) {
            $from = $interview->status;

            $interview->update([
                'status' => InterviewStatus::COMPLETED,
                'completed_at' => now(),
                'updated_by' => $actingUser->id,
            ]);

            $this->recordHistory($interview, $from, InterviewStatus::COMPLETED, $actingUser, $remarks);
            event(new InterviewStatusChanged($interview, $from, InterviewStatus::COMPLETED, $actingUser));

            return $interview;
        });
    }

    public function cancel(Interview $interview, User $actingUser, string $reason): Interview
    {
        if (! $interview->status->canTransitionTo(InterviewStatus::CANCELLED)) {
            throw new InterviewException('Only a scheduled or rescheduled interview can be cancelled.');
        }

        return DB::transaction(function () use ($interview, $actingUser, $reason) {
            $from = $interview->status;

            $interview->update([
                'status' => InterviewStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $actingUser->id,
            ]);

            $this->recordHistory($interview, $from, InterviewStatus::CANCELLED, $actingUser, $reason);
            event(new InterviewStatusChanged($interview, $from, InterviewStatus::CANCELLED, $actingUser));

            return $interview;
        });
    }

    private function recordHistory(Interview $interview, ?InterviewStatus $from, InterviewStatus $to, User $actingUser, ?string $remarks): void
    {
        InterviewStatusHistory::create([
            'interview_id' => $interview->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actingUser->id,
            'remarks' => $remarks,
        ]);
    }

    private function normalizeScheduledAt(string $value): Carbon
    {
        return Carbon::parse($value)->utc();
    }
}
