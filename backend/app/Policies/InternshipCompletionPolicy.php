<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\InternshipAssignment;
use App\Models\InternshipCompletion;
use App\Models\User;

/**
 * Object-level authorization for Internship Completion & PPO's
 * completion records, layered under the route-level
 * role:PLACEMENT_ADMIN,SUPER_ADMIN / role:FACULTY / role:STUDENT gates
 * in routes/api.php - same pattern as InternshipDocumentPolicy (see
 * its docblock).
 *
 * Every ownership check is resolved by walking InternshipCompletion ->
 * internshipAssignment -> faculty_id/student_id - never a direct
 * student_id/faculty_id column on InternshipCompletion itself (there
 * isn't one).
 *
 * FACULTY gets create()/update()/finalize()/view() - but only against
 * their OWN assigned student's assignment/completion (per the module
 * spec's "Faculty may complete only assigned students" - "Completion
 * cannot be created for another student's assignment"). STUDENT gets
 * view() only against their OWN completion - never
 * create()/update()/finalize() at all (per the module spec's "Student
 * can view their own completion/PPO but cannot modify it" - there is
 * no route that would even reach these write methods for a student,
 * but the policy enforces it independently too, as defense in depth).
 * PLACEMENT_ADMIN/SUPER_ADMIN get read-only access (viewAny()/
 * view()) - there is no admin create()/update()/finalize() at all,
 * mirroring InternshipDocumentPolicy's own "Do NOT give admin an
 * unrestricted ability to alter student-owned [records]" precedent -
 * the module spec's own Admin API list for this module is GET-only.
 */
class InternshipCompletionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $user->roleName() === RoleName::FACULTY || $user->roleName() === RoleName::STUDENT;
    }

    public function view(User $user, InternshipCompletion $completion): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->facultyOwns($user, $completion->internshipAssignment)) {
            return true;
        }

        return $this->studentOwns($user, $completion->internshipAssignment);
    }

    /**
     * $assignment is the InternshipAssignment a completion record would
     * be created against - passed explicitly (via
     * `Gate::authorize('create', [InternshipCompletion::class, $assignment])`)
     * since no InternshipCompletion instance exists yet at creation
     * time. Faculty-only - see this class's own docblock.
     */
    public function create(User $user, InternshipAssignment $assignment): bool
    {
        return $this->facultyOwns($user, $assignment);
    }

    /**
     * Faculty-owner ONLY - this is an object-level ownership check, not
     * a lifecycle/state check. Whether the record is already FINALIZED
     * is deliberately NOT checked here: that is a business-rule
     * question, not an authorization question, and it is enforced as
     * the sole, load-bearing check in
     * InternshipCompletionService::updateCompletion() (which throws
     * InternshipCompletionException -> HTTP 422). Mixing the two here
     * would make a terminal-record write on your OWN assignment look
     * identical, over HTTP, to trying to touch someone else's
     * assignment (both would surface as 403), which is exactly the
     * ambiguity this method exists to avoid: ownership failures are
     * 403, invalid-state-on-an-owned-record failures are 422 - see
     * InternshipCompletionService's own docblock ("IMMUTABILITY").
     */
    public function update(User $user, InternshipCompletion $completion): bool
    {
        return $this->facultyOwns($user, $completion->internshipAssignment);
    }

    /**
     * Faculty-owner ONLY - same reasoning as update() above. Whether
     * the record is already FINALIZED is left entirely to
     * InternshipCompletionService::finalizeCompletion(), which throws a
     * 422 when the DRAFT -> FINALIZED transition is no longer valid.
     */
    public function finalize(User $user, InternshipCompletion $completion): bool
    {
        return $this->facultyOwns($user, $completion->internshipAssignment);
    }

    private function facultyOwns(User $user, InternshipAssignment $assignment): bool
    {
        return $user->faculty !== null && $assignment->faculty_id === $user->faculty->id;
    }

    private function studentOwns(User $user, InternshipAssignment $assignment): bool
    {
        return $user->student !== null && $assignment->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
