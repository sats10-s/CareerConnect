<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\InternshipAssignmentStatus;
use App\Enums\RoleName;
use App\Events\InternshipAssignmentStatusChanged;
use App\Exceptions\InternshipAssignmentException;
use App\Models\Application;
use App\Models\Faculty;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\InternshipAssignmentFacultyHistory;
use App\Models\InternshipAssignmentStatusHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single place every internship-assignment write goes through:
 * creating an assignment, activating/completing/cancelling one, and
 * reassigning its faculty coordinator. Each method is atomic (its own
 * DB::transaction()) and writes exactly one paired
 * InternshipAssignmentStatusHistory row per status change (or
 * InternshipAssignmentFacultyHistory row per reassignment), alongside
 * dispatching InternshipAssignmentStatusChanged where relevant -
 * callers (Api\Admin\InternshipAssignmentController) never touch
 * `internship_assignments`, `internship_assignment_status_history` or
 * `internship_assignment_faculty_history` directly.
 *
 * Never duplicates App\Services\ApplicationService or
 * App\Services\InterviewService logic - the SELECTED application
 * remains the single source of truth for who applied to what;
 * creating an assignment never mutates the application's own status
 * (see create()'s docblock), and internship_id/student_id are always
 * DERIVED from the application, never trusted from the client (see
 * Api\Admin\InternshipAssignmentController::store(), which resolves
 * the Application/Faculty models from validated IDs and passes them
 * in - this service never re-trusts a raw client-supplied
 * student_id/internship_id).
 *
 * Every method takes the acting User explicitly (never re-derives it)
 * so callers are the ones responsible for deriving it from
 * $request->user().
 */
class InternshipAssignmentService
{
    /**
     * Creates a new internship assignment for a SELECTED internship
     * application and its initial (null -> ASSIGNED) status history
     * row, atomically. Deliberately does NOT touch the application's
     * own status or the internship opportunity's publication status -
     * both remain exactly as they were (see the module spec's
     * "do not automatically modify the application's SELECTED status"
     * / "do not automatically modify internship opportunity status").
     *
     * $assignedAt/$notes are the only two client-suppliable fields
     * beyond application_id/faculty_id (see StoreInternshipAssignmentRequest)
     * - everything else (internship_id, student_id, assigned_by,
     * status) is derived server-side.
     */
    public function create(Application $application, Faculty $faculty, User $actingUser, ?string $assignedAt = null, ?string $notes = null): InternshipAssignment
    {
        $this->assertApplicationAssignable($application);
        $this->assertFacultyEligible($faculty);

        if (InternshipAssignment::where('application_id', $application->id)->exists()) {
            throw new InternshipAssignmentException('This application already has an internship assignment.');
        }

        try {
            return DB::transaction(function () use ($application, $faculty, $actingUser, $assignedAt, $notes) {
                $assignment = InternshipAssignment::create([
                    'application_id' => $application->id,
                    // Derived from the selected application, never from
                    // the client - see this class's own docblock.
                    'internship_id' => $application->applicable_id,
                    'student_id' => $application->student_id,
                    'faculty_id' => $faculty->id,
                    'assigned_by' => $actingUser->id,
                    'assigned_at' => $this->normalizeDateTime($assignedAt) ?? now(),
                    'status' => InternshipAssignmentStatus::ASSIGNED,
                    'notes' => $notes,
                ]);

                $this->recordStatusHistory($assignment, null, InternshipAssignmentStatus::ASSIGNED, $actingUser, null);
                event(new InternshipAssignmentStatusChanged($assignment, null, InternshipAssignmentStatus::ASSIGNED, $actingUser));

                return $assignment;
            });
        } catch (QueryException $e) {
            // Safety net for a race between the exists() check above and
            // this insert - the DB-level unique constraint on
            // internship_assignments.application_id is the actual
            // source of truth; this just turns its violation into the
            // same clean business error rather than a raw 500. Same
            // pattern as ApplicationService::apply() /
            // PlacementDriveService::attachOpportunity().
            if ($e->getCode() === '23000') {
                throw new InternshipAssignmentException('This application already has an internship assignment.');
            }

            throw $e;
        }
    }

    public function activate(InternshipAssignment $assignment, User $actingUser, ?string $startedAt = null): InternshipAssignment
    {
        if (! $assignment->status->canTransitionTo(InternshipAssignmentStatus::ACTIVE)) {
            throw new InternshipAssignmentException('Only an assigned internship assignment can be activated.');
        }

        return DB::transaction(function () use ($assignment, $actingUser, $startedAt) {
            $from = $assignment->status;

            $assignment->update([
                'status' => InternshipAssignmentStatus::ACTIVE,
                'started_at' => $this->normalizeDateTime($startedAt) ?? $assignment->started_at ?? now(),
            ]);

            $this->recordStatusHistory($assignment, $from, InternshipAssignmentStatus::ACTIVE, $actingUser, null);
            event(new InternshipAssignmentStatusChanged($assignment, $from, InternshipAssignmentStatus::ACTIVE, $actingUser));

            return $assignment;
        });
    }

    /**
     * Marks an assignment COMPLETED. Deliberately does not create an
     * evaluation record - evaluation is an explicitly out-of-scope
     * future module (see the module spec's "do not create evaluation
     * automatically").
     */
    public function complete(InternshipAssignment $assignment, User $actingUser, ?string $remarks = null, ?string $endedAt = null): InternshipAssignment
    {
        if (! $assignment->status->canTransitionTo(InternshipAssignmentStatus::COMPLETED)) {
            throw new InternshipAssignmentException('Only an active internship assignment can be marked completed.');
        }

        return DB::transaction(function () use ($assignment, $actingUser, $remarks, $endedAt) {
            $from = $assignment->status;

            $assignment->update([
                'status' => InternshipAssignmentStatus::COMPLETED,
                'ended_at' => $this->normalizeDateTime($endedAt) ?? now(),
            ]);

            $this->recordStatusHistory($assignment, $from, InternshipAssignmentStatus::COMPLETED, $actingUser, $remarks);
            event(new InternshipAssignmentStatusChanged($assignment, $from, InternshipAssignmentStatus::COMPLETED, $actingUser));

            return $assignment;
        });
    }

    /**
     * Cancels an assignment. Deliberately does not touch the
     * originating application - it remains SELECTED unless another
     * explicit business action changes it (see the module spec's
     * "do not automatically reject the original application").
     */
    public function cancel(InternshipAssignment $assignment, User $actingUser, string $reason): InternshipAssignment
    {
        if (! $assignment->status->canTransitionTo(InternshipAssignmentStatus::CANCELLED)) {
            throw new InternshipAssignmentException('Only an assigned or active internship assignment can be cancelled.');
        }

        return DB::transaction(function () use ($assignment, $actingUser, $reason) {
            $from = $assignment->status;

            $assignment->update(['status' => InternshipAssignmentStatus::CANCELLED]);

            $this->recordStatusHistory($assignment, $from, InternshipAssignmentStatus::CANCELLED, $actingUser, $reason);
            event(new InternshipAssignmentStatusChanged($assignment, $from, InternshipAssignmentStatus::CANCELLED, $actingUser));

            return $assignment;
        });
    }

    /**
     * Reassigns the faculty coordinator on THIS SAME assignment row -
     * never creates a second internship_assignment for the same
     * application (see the migration's permanent unique(application_id)
     * constraint). The assignment's own status/status history is
     * completely untouched by a reassignment; see
     * internship_assignment_faculty_history's docblock for why this is
     * a dedicated table rather than a status-history remarks entry.
     */
    public function reassignFaculty(InternshipAssignment $assignment, Faculty $newFaculty, User $actingUser, ?string $remarks = null): InternshipAssignment
    {
        if ($assignment->status->isTerminal()) {
            throw new InternshipAssignmentException('A completed or cancelled assignment can no longer be reassigned.');
        }

        $this->assertFacultyEligible($newFaculty);

        if ($newFaculty->id === $assignment->faculty_id) {
            throw new InternshipAssignmentException('This assignment is already coordinated by that faculty member.');
        }

        return DB::transaction(function () use ($assignment, $newFaculty, $actingUser, $remarks) {
            $fromFacultyId = $assignment->faculty_id;

            $assignment->update(['faculty_id' => $newFaculty->id]);

            InternshipAssignmentFacultyHistory::create([
                'internship_assignment_id' => $assignment->id,
                'from_faculty_id' => $fromFacultyId,
                'to_faculty_id' => $newFaculty->id,
                'changed_by' => $actingUser->id,
                'remarks' => $remarks,
            ]);

            return $assignment->fresh();
        });
    }

    /**
     * The application must be SELECTED and for an Internship (never a
     * JobPosting) - internship assignments only ever coordinate
     * internships, not job placements (see the module's business flow
     * docblock at the top of the spec).
     */
    private function assertApplicationAssignable(Application $application): void
    {
        if ($application->status !== ApplicationStatus::SELECTED) {
            throw new InternshipAssignmentException(sprintf(
                'Only a SELECTED application can receive an internship assignment (this application is %s).',
                $application->status->value,
            ));
        }

        if ($application->applicable_type !== Internship::class) {
            throw new InternshipAssignmentException('Only an internship application can receive an internship assignment.');
        }
    }

    /**
     * A faculty coordinator must have an active User account and hold
     * the FACULTY role (this codebase has no separate "Internship
     * Coordinator" role in its existing role model - see
     * App\Enums\RoleName - so FACULTY is the sole role eligible here).
     */
    private function assertFacultyEligible(Faculty $faculty): void
    {
        $user = $faculty->user;

        if ($user === null || ! $user->is_active) {
            throw new InternshipAssignmentException('The selected faculty member is not active.');
        }

        if ($user->roleName() !== RoleName::FACULTY) {
            throw new InternshipAssignmentException('The selected user is not a faculty member.');
        }
    }

    private function recordStatusHistory(InternshipAssignment $assignment, ?InternshipAssignmentStatus $from, InternshipAssignmentStatus $to, User $actingUser, ?string $remarks): void
    {
        InternshipAssignmentStatusHistory::create([
            'internship_assignment_id' => $assignment->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actingUser->id,
            'remarks' => $remarks,
        ]);
    }

    private function normalizeDateTime(?string $value): ?Carbon
    {
        return $value !== null ? Carbon::parse($value)->utc() : null;
    }
}
