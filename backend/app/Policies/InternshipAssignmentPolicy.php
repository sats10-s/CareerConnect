<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\InternshipAssignment;
use App\Models\User;

/**
 * Object-level authorization for Internship Assignment + Faculty
 * Coordination, layered under the route-level role:PLACEMENT_ADMIN,
 * SUPER_ADMIN / role:FACULTY gates in routes/api.php - same pattern as
 * InterviewPolicy/PlacementDrivePolicy (see their docblocks).
 *
 * Faculty access is STRICTLY assignment-based - a faculty member can
 * never see all students or all internships, only what
 * owns() resolves for them (see this class's docblock on owns()).
 * Placement Admin / Super Admin can manage every assignment; Faculty
 * can only ever VIEW their own assignments - they cannot create,
 * reassign, activate, complete or cancel anything (per the module
 * spec's explicit "Faculty ... cannot assign students / cannot
 * reassign themselves or others / cannot change faculty_id"), so
 * update() is admin-only with no faculty branch at all.
 *
 * view() ADDITIONALLY allows the assignment's own student (see
 * studentOwns()) - added for the Attendance Management module, which
 * needs `GET /api/student/internship-assignments/{assignment}/attendance`
 * to authorize a student against their own assignment. This is a pure
 * widening: it does not change admin or faculty behavior, and nothing
 * in the pre-existing Internship Assignment module ever calls view()
 * with a student actor (its own routes are all role:PLACEMENT_ADMIN,
 * SUPER_ADMIN / role:FACULTY), so this addition cannot affect any of
 * its existing authorization tests.
 */
class InternshipAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, InternshipAssignment $assignment): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->owns($user, $assignment)) {
            return true;
        }

        return $this->studentOwns($user, $assignment);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, InternshipAssignment $assignment): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * The IDOR/BOLA guard - ownership is always derived by resolving
     * the AUTHENTICATED user's own Faculty record and comparing its id
     * to the assignment's faculty_id, never trusted from the URL/
     * request, and never assumed to equal the User id itself (a
     * Faculty row's primary key is its own id, distinct from
     * user_id - see App\Models\Faculty).
     */
    private function owns(User $user, InternshipAssignment $assignment): bool
    {
        return $user->faculty !== null
            && $assignment->faculty_id === $user->faculty->id;
    }

    private function studentOwns(User $user, InternshipAssignment $assignment): bool
    {
        return $user->student !== null
            && $assignment->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
