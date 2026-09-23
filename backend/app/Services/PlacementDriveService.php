<?php

namespace App\Services;

use App\Enums\PlacementDriveStatus;
use App\Events\PlacementDriveStatusChanged;
use App\Exceptions\PlacementDriveException;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\PlacementDrive;
use App\Models\PlacementDriveOpportunity;
use App\Models\PlacementDriveStatusHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of every placement-drive lifecycle change: create,
 * update (scheduling/descriptive fields only), publish, close, cancel,
 * attach/detach an existing JobPosting/Internship. Mirrors
 * App\Services\InterviewService and App\Services\ApplicationService in
 * shape - every method is atomic, and every status change writes
 * exactly one paired PlacementDriveStatusHistory row.
 *
 * ARCHITECTURE - a placement drive is a college recruitment EVENT that
 * groups together existing JobPosting/Internship rows through the
 * `placement_drive_opportunities` join table; it never owns
 * applications or interviews of its own. Application/interview data is
 * only ever aggregated by walking
 * placement_drive_opportunities -> applications -> interviews (see
 * App\Http\Controllers\Concerns\ComputesPlacementDriveStats), and this
 * service never writes to `applications`, `application_status_history`,
 * `interviews`, or `interview_status_history` - App\Services\
 * ApplicationService and App\Services\InterviewService remain the only
 * writers of those tables, completely untouched by this module.
 *
 * `scheduled_at`/`application_deadline` are normalized to UTC before
 * being persisted, the same convention
 * InterviewService::normalizeScheduledAt() already established.
 */
class PlacementDriveService
{
    /**
     * The short, API-facing opportunity_type strings (matching the
     * `type` query-param convention already used elsewhere in this app
     * - see Api\Admin\InterviewController::applicableClassFor()) mapped
     * to the actual model class each one attaches. `opportunity_type`
     * is stored as the full class name in the database, exactly like
     * `applications.applicable_type` already does - no morph map is
     * registered anywhere in this app.
     */
    private const OPPORTUNITY_TYPES = [
        'job' => JobPosting::class,
        'internship' => Internship::class,
    ];

    /**
     * @param array<string, mixed> $data validated StorePlacementDriveRequest data
     */
    public function create(array $data, User $actingUser): PlacementDrive
    {
        $this->assertDeadlineNotAfterSchedule($data['application_deadline'] ?? null, $data['scheduled_at'] ?? null);

        return DB::transaction(function () use ($data, $actingUser) {
            $drive = PlacementDrive::create([
                'company_id' => $data['company_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'drive_type' => $data['drive_type'],
                'venue' => $data['venue'] ?? null,
                'scheduled_at' => $this->normalizeDateTime($data['scheduled_at'] ?? null),
                'application_deadline' => $this->normalizeDateTime($data['application_deadline'] ?? null),
                'status' => PlacementDriveStatus::DRAFT,
                'created_by' => $actingUser->id,
            ]);

            $this->recordHistory($drive, null, PlacementDriveStatus::DRAFT, $actingUser, null);
            event(new PlacementDriveStatusChanged($drive, null, PlacementDriveStatus::DRAFT, $actingUser));

            return $drive;
        });
    }

    /**
     * Edits scheduling/descriptive fields only - never `status` (that
     * always goes through publish()/close()/cancel() below, each of
     * which writes its own history row; a plain field edit here never
     * does - see UpdatePlacementDriveRequest's explicit `prohibited`
     * rule on `status`).
     *
     * Company changes are blocked once the drive has left DRAFT -
     * nothing in this app re-parents a published drive's already-
     * attached opportunities (which were validated against the
     * ORIGINAL company) if the company changed underneath them, so
     * this is a hard rule, not merely a default.
     *
     * Defensively re-validates the deadline-vs-schedule rule against
     * the MERGED (existing + incoming) values, not just whichever
     * fields this one request happens to include - two separate PATCH
     * calls (one moving scheduled_at earlier, a later one never
     * touching application_deadline) could otherwise leave the row in
     * a state no single request's own validation ever saw. publish()
     * below re-checks this again as a final safety net.
     *
     * @param array<string, mixed> $data validated UpdatePlacementDriveRequest data
     */
    public function update(PlacementDrive $drive, array $data, User $actingUser): PlacementDrive
    {
        if (array_key_exists('company_id', $data)
            && (int) $data['company_id'] !== $drive->company_id
            && $drive->status !== PlacementDriveStatus::DRAFT) {
            throw new PlacementDriveException('The company cannot be changed once a drive has left DRAFT.');
        }

        $mergedScheduledAt = array_key_exists('scheduled_at', $data) ? $data['scheduled_at'] : $drive->scheduled_at;
        $mergedDeadline = array_key_exists('application_deadline', $data) ? $data['application_deadline'] : $drive->application_deadline;
        $this->assertDeadlineNotAfterSchedule($mergedDeadline, $mergedScheduledAt);

        $updates = array_intersect_key($data, array_flip([
            'company_id', 'title', 'description', 'drive_type', 'venue', 'scheduled_at', 'application_deadline',
        ]));

        if (array_key_exists('scheduled_at', $updates)) {
            $updates['scheduled_at'] = $this->normalizeDateTime($updates['scheduled_at']);
        }

        if (array_key_exists('application_deadline', $updates)) {
            $updates['application_deadline'] = $this->normalizeDateTime($updates['application_deadline']);
        }

        $updates['updated_by'] = $actingUser->id;

        $drive->update($updates);

        return $drive;
    }

    /**
     * Publishing a drive does NOT change any attached opportunity's
     * own publication status - see attachOpportunity()'s docblock.
     */
    public function publish(PlacementDrive $drive, User $actingUser): PlacementDrive
    {
        if (! $drive->status->canTransitionTo(PlacementDriveStatus::PUBLISHED)) {
            throw new PlacementDriveException('Only a draft placement drive can be published.');
        }

        if ($drive->company === null) {
            throw new PlacementDriveException('This drive has no valid company.');
        }

        if ($drive->opportunities()->count() === 0) {
            throw new PlacementDriveException('At least one job or internship must be attached before publishing.');
        }

        $this->assertDeadlineNotAfterSchedule($drive->application_deadline, $drive->scheduled_at);

        return $this->transitionTo($drive, PlacementDriveStatus::PUBLISHED, $actingUser, null);
    }

    /**
     * Closing a drive never touches applications or interviews - it is
     * purely an event-level status change (see the class docblock's
     * architecture note).
     */
    public function close(PlacementDrive $drive, User $actingUser): PlacementDrive
    {
        if (! $drive->status->canTransitionTo(PlacementDriveStatus::CLOSED)) {
            throw new PlacementDriveException('Only a published placement drive can be closed.');
        }

        return $this->transitionTo($drive, PlacementDriveStatus::CLOSED, $actingUser, null);
    }

    /**
     * Cancelling a drive never auto-rejects its applicants or cancels
     * their interviews - same architecture note as close(). Reachable
     * from DRAFT or PUBLISHED (see PlacementDriveStatus::canTransitionTo()).
     */
    public function cancel(PlacementDrive $drive, User $actingUser, string $reason): PlacementDrive
    {
        if (! $drive->status->canTransitionTo(PlacementDriveStatus::CANCELLED)) {
            throw new PlacementDriveException('This placement drive can no longer be cancelled.');
        }

        return DB::transaction(function () use ($drive, $actingUser, $reason) {
            $from = $drive->status;

            $drive->update([
                'status' => PlacementDriveStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => $actingUser->id,
                'updated_by' => $actingUser->id,
            ]);

            $this->recordHistory($drive, $from, PlacementDriveStatus::CANCELLED, $actingUser, $reason);
            event(new PlacementDriveStatusChanged($drive, $from, PlacementDriveStatus::CANCELLED, $actingUser));

            return $drive;
        });
    }

    /**
     * Attaches an existing JobPosting/Internship to this drive. Never
     * touches the opportunity's own `status`/`is_active` - a drive
     * attachment is purely a grouping record (see
     * PlacementDriveOpportunity's docblock); nothing here calls
     * $opportunity->update(...).
     */
    public function attachOpportunity(PlacementDrive $drive, string $opportunityType, int $opportunityId, User $actingUser): PlacementDriveOpportunity
    {
        if ($drive->status->isTerminal()) {
            throw new PlacementDriveException('Opportunities cannot be attached to a closed or cancelled drive.');
        }

        $modelClass = self::OPPORTUNITY_TYPES[$opportunityType] ?? null;

        if ($modelClass === null) {
            throw new PlacementDriveException('Unsupported opportunity type.');
        }

        $opportunity = $modelClass::find($opportunityId);

        if ($opportunity === null) {
            throw new PlacementDriveException('That opportunity could not be found.');
        }

        if ($opportunity->company_id !== $drive->company_id) {
            throw new PlacementDriveException("That opportunity does not belong to this drive's company.");
        }

        $alreadyAttached = $drive->opportunities()
            ->where('opportunity_type', $modelClass)
            ->where('opportunity_id', $opportunityId)
            ->exists();

        if ($alreadyAttached) {
            throw new PlacementDriveException('This opportunity is already attached to this drive.');
        }

        try {
            return $drive->opportunities()->create([
                'opportunity_type' => $modelClass,
                'opportunity_id' => $opportunityId,
            ]);
        } catch (QueryException $e) {
            // Safety net for a race between the exists() check above and
            // this insert - the same pattern
            // App\Services\ApplicationService::apply() already uses for
            // duplicate applications. SQLSTATE 23000 is the driver-
            // agnostic "integrity constraint violation" class (MySQL in
            // production, SQLite in tests).
            if ($e->getCode() === '23000') {
                throw new PlacementDriveException('This opportunity is already attached to this drive.');
            }

            throw $e;
        }
    }

    /**
     * Detaches an opportunity from this drive - deletes only the
     * `placement_drive_opportunities` row, never the underlying
     * JobPosting/Internship.
     */
    public function detachOpportunity(PlacementDrive $drive, PlacementDriveOpportunity $opportunity, User $actingUser): void
    {
        if ($opportunity->placement_drive_id !== $drive->id) {
            throw new PlacementDriveException('That opportunity is not attached to this drive.');
        }

        if ($drive->status->isTerminal()) {
            throw new PlacementDriveException('Opportunities cannot be detached from a closed or cancelled drive.');
        }

        $opportunity->delete();
    }

    private function transitionTo(PlacementDrive $drive, PlacementDriveStatus $target, User $actingUser, ?string $remarks): PlacementDrive
    {
        return DB::transaction(function () use ($drive, $target, $actingUser, $remarks) {
            $from = $drive->status;

            $drive->update(['status' => $target, 'updated_by' => $actingUser->id]);

            $this->recordHistory($drive, $from, $target, $actingUser, $remarks);
            event(new PlacementDriveStatusChanged($drive, $from, $target, $actingUser));

            return $drive;
        });
    }

    private function recordHistory(PlacementDrive $drive, ?PlacementDriveStatus $from, PlacementDriveStatus $to, User $actingUser, ?string $remarks): void
    {
        PlacementDriveStatusHistory::create([
            'placement_drive_id' => $drive->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actingUser->id,
            'remarks' => $remarks,
        ]);
    }

    private function assertDeadlineNotAfterSchedule(mixed $deadline, mixed $scheduledAt): void
    {
        if (! $deadline || ! $scheduledAt) {
            return;
        }

        $deadlineAt = $deadline instanceof Carbon ? $deadline : Carbon::parse($deadline);
        $scheduleAt = $scheduledAt instanceof Carbon ? $scheduledAt : Carbon::parse($scheduledAt);

        if ($deadlineAt->gt($scheduleAt)) {
            throw new PlacementDriveException('The application deadline must not be after the scheduled drive date.');
        }
    }

    private function normalizeDateTime(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
