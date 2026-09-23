<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Attendance;
use App\Models\InternshipAssignment;
use App\Models\User;

/**
 * Object-level authorization for Attendance Management, layered under
 * the route-level role:PLACEMENT_ADMIN,SUPER_ADMIN / role:FACULTY /
 * role:STUDENT gates in routes/api.php - same pattern as
 * InternshipAssignmentPolicy (see its docblock).
 *
 * Every ownership check is resolved by walking Attendance ->
 * internshipAssignment -> faculty_id/student_id, exactly the "Attendance
 * must be scoped through InternshipAssignment -> Faculty/Student" chain
 * the module spec requires - never a direct student_id/faculty_id
 * column on Attendance itself (there isn't one), and never the
 * authenticated User's own id (a Faculty/Student row's primary key is
 * its own id, distinct from user_id - see
 * InternshipAssignmentPolicy::owns()'s docblock, which this mirrors).
 *
 * STUDENT never gets create()/update() access at all - only view() -
 * per the module spec's "Student cannot create/update/delete
 * attendance". PLACEMENT_ADMIN/SUPER_ADMIN can view/update (correct)
 * every record but never create() - there is no admin create endpoint
 * in this module (see routes/api.php); marking new attendance is
 * exclusively a faculty action.
 */
class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $user->roleName() === RoleName::FACULTY || $user->roleName() === RoleName::STUDENT;
    }

    public function view(User $user, Attendance $attendance): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->facultyOwns($user, $attendance)) {
            return true;
        }

        return $this->studentOwns($user, $attendance);
    }

    /**
     * $assignment is the InternshipAssignment attendance would be
     * created against - passed explicitly (via
     * Gate::authorize('create', [Attendance::class, $assignment])`)
     * since no Attendance instance exists yet at creation time. Admin
     * is deliberately NOT authorized here - see this class's own
     * docblock.
     */
    public function create(User $user, InternshipAssignment $assignment): bool
    {
        return $user->roleName() === RoleName::FACULTY
            && $user->faculty !== null
            && $assignment->faculty_id === $user->faculty->id;
    }

    public function update(User $user, Attendance $attendance): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->facultyOwns($user, $attendance);
    }

    private function facultyOwns(User $user, Attendance $attendance): bool
    {
        return $user->faculty !== null
            && $attendance->internshipAssignment->faculty_id === $user->faculty->id;
    }

    private function studentOwns(User $user, Attendance $attendance): bool
    {
        return $user->student !== null
            && $attendance->internshipAssignment->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
