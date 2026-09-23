<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\OpportunityStatus;
use App\Events\ApplicationStatusChanged;
use App\Exceptions\ApplicationException;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single place every application-lifecycle write goes through:
 * creating an application, withdrawing one, and an admin moving one
 * to a new status. Each method is atomic (its own DB::transaction())
 * and writes exactly one paired ApplicationStatusHistory row per
 * change, alongside dispatching ApplicationStatusChanged - callers
 * (Api\Student\OpportunityController, Api\Student\ApplicationController,
 * Api\Admin\ApplicationController) never touch `applications` or
 * `application_status_history` directly, so this logic can never end
 * up duplicated or drifting between controllers.
 *
 * Every method takes the acting User explicitly (never re-derives it)
 * so callers are the ones responsible for deriving it from
 * $request->user() - this service never trusts a client-supplied
 * student/user id.
 */
class ApplicationService
{
    public function __construct(
        private readonly OpportunityEligibilityService $eligibilityService,
    ) {}

    /**
     * Creates a new application and its initial (null -> APPLIED)
     * history row atomically. Throws ApplicationException for every
     * business-rule rejection (opportunity not published, duplicate
     * application, ineligible student when enforcement is on) - never
     * lets the caller construct an invalid application.
     */
    public function apply(Student $student, JobPosting|Internship $opportunity, User $actingUser): Application
    {
        if ($opportunity->status !== OpportunityStatus::PUBLISHED) {
            throw new ApplicationException('This opportunity is not currently open for applications.');
        }

        $alreadyApplied = Application::query()
            ->where('student_id', $student->id)
            ->where('applicable_type', $opportunity::class)
            ->where('applicable_id', $opportunity->id)
            ->exists();

        if ($alreadyApplied) {
            throw new ApplicationException('You have already applied to this opportunity.');
        }

        $eligibility = $this->eligibilityService->evaluate($student, $opportunity);

        if ($eligibility['eligibility_enforced'] && ! $eligibility['eligible']) {
            throw new ApplicationException(
                'You do not meet the eligibility criteria for this opportunity.',
                $eligibility['reasons'],
            );
        }

        try {
            return DB::transaction(function () use ($student, $opportunity, $actingUser) {
                $application = Application::create([
                    'student_id' => $student->id,
                    'applicable_type' => $opportunity::class,
                    'applicable_id' => $opportunity->id,
                    'status' => ApplicationStatus::APPLIED,
                    'applied_at' => now(),
                ]);

                ApplicationStatusHistory::create([
                    'application_id' => $application->id,
                    'from_status' => null,
                    'to_status' => ApplicationStatus::APPLIED,
                    'changed_by' => $actingUser->id,
                    'remarks' => null,
                ]);

                event(new ApplicationStatusChanged($application, null, ApplicationStatus::APPLIED, $actingUser));

                return $application;
            });
        } catch (QueryException $e) {
            // Safety net for a race between the exists() check above and
            // this insert (two near-simultaneous applies for the same
            // student+opportunity) - the DB-level unique constraint
            // (applications_unique_per_student) is the actual source of
            // truth; this just turns its violation into the same clean
            // business error rather than a raw 500. SQLSTATE 23000 is the
            // standard "integrity constraint violation" class reported by
            // both MySQL (production) and SQLite (the test suite), so
            // this check is driver-agnostic - deliberately not keyed to
            // MySQL's specific error code 1062.
            if ($e->getCode() === '23000') {
                throw new ApplicationException('You have already applied to this opportunity.');
            }

            throw $e;
        }
    }

    /**
     * Withdraws an application - allowed from any non-terminal status
     * (see ApplicationStatus::isWithdrawable()). Ownership is the
     * caller's responsibility (see ApplicationPolicy::withdraw / the
     * Gate::authorize() call in Api\Student\ApplicationController).
     */
    public function withdraw(Application $application, User $actingUser, ?string $remarks = null): Application
    {
        if (! $application->status->isWithdrawable()) {
            throw new ApplicationException('This application can no longer be withdrawn.');
        }

        return DB::transaction(function () use ($application, $actingUser, $remarks) {
            $from = $application->status;

            $application->update([
                'status' => ApplicationStatus::WITHDRAWN,
                'withdrawn_at' => now(),
            ]);

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'from_status' => $from,
                'to_status' => ApplicationStatus::WITHDRAWN,
                'changed_by' => $actingUser->id,
                'remarks' => $remarks,
            ]);

            event(new ApplicationStatusChanged($application, $from, ApplicationStatus::WITHDRAWN, $actingUser));

            return $application;
        });
    }

    /**
     * Admin-driven status transition - validated against
     * ApplicationStatus::canTransitionTo() (the current status decides
     * what's legal, which is why this check lives here rather than in
     * the FormRequest). Setting `selected_at`/`rejection_reason` on the
     * application itself, alongside the history row, keeps those two
     * "when/why" fields in sync with the transition that produced them
     * without a caller having to set them separately.
     */
    public function updateStatus(Application $application, ApplicationStatus $target, User $actingUser, ?string $remarks = null): Application
    {
        $from = $application->status;

        if (! $from->canTransitionTo($target)) {
            throw new ApplicationException(sprintf(
                'This application cannot move from %s to %s.',
                $from->value,
                $target->value,
            ));
        }

        return DB::transaction(function () use ($application, $from, $target, $actingUser, $remarks) {
            $updates = ['status' => $target];

            if ($target === ApplicationStatus::SELECTED) {
                $updates['selected_at'] = now();
            }

            if ($target === ApplicationStatus::REJECTED) {
                $updates['rejection_reason'] = $remarks;
            }

            $application->update($updates);

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'from_status' => $from,
                'to_status' => $target,
                'changed_by' => $actingUser->id,
                'remarks' => $remarks,
            ]);

            event(new ApplicationStatusChanged($application, $from, $target, $actingUser));

            return $application;
        });
    }
}
