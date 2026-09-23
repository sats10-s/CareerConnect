<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\InternshipAssignment;
use App\Models\User;
use App\Models\WeeklyProgressReport;

/**
 * Object-level authorization for Weekly Progress Reports, layered
 * under the route-level role:PLACEMENT_ADMIN,SUPER_ADMIN / role:FACULTY
 * / role:STUDENT gates in routes/api.php - same pattern as
 * AttendancePolicy (see its docblock).
 *
 * Every ownership check is resolved by walking WeeklyProgressReport ->
 * internshipAssignment -> faculty_id/student_id - never a direct
 * student_id/faculty_id column on WeeklyProgressReport itself (there
 * isn't one), and never the authenticated User's own id (a
 * Faculty/Student row's primary key is its own id, distinct from
 * user_id - see InternshipAssignmentPolicy::owns()'s docblock, which
 * this mirrors).
 *
 * STUDENT gets create()/update()/submit() - but only against their OWN
 * assignment/report - and view(); never review(). FACULTY gets
 * view()/review() against their OWN assigned students' reports; never
 * create()/update()/submit() - a faculty member can never write a
 * student's own report content, only move it through the review
 * lifecycle (per the module spec's "Faculty cannot ... edit student's
 * work content / create reports on behalf of students"). PLACEMENT_ADMIN/
 * SUPER_ADMIN get read-only access (viewAny()/view()) - there is no
 * admin update()/create()/submit()/review() at all, since this module
 * intentionally has no admin write endpoint (see the module spec's "do
 * not add unnecessary admin write privileges").
 */
class WeeklyProgressReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $user->roleName() === RoleName::FACULTY || $user->roleName() === RoleName::STUDENT;
    }

    public function view(User $user, WeeklyProgressReport $report): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->facultyOwns($user, $report)) {
            return true;
        }

        return $this->studentOwns($user, $report);
    }

    /**
     * $assignment is the InternshipAssignment a draft would be created
     * against - passed explicitly (via
     * `Gate::authorize('create', [WeeklyProgressReport::class, $assignment])`)
     * since no WeeklyProgressReport instance exists yet at creation
     * time. Student-only - see this class's own docblock.
     */
    public function create(User $user, InternshipAssignment $assignment): bool
    {
        return $user->roleName() === RoleName::STUDENT
            && $user->student !== null
            && $assignment->student_id === $user->student->id;
    }

    /**
     * Content edits - student-owner only. Faculty NEVER gets update()
     * access (see this class's own docblock); a faculty member's only
     * write path is review().
     */
    public function update(User $user, WeeklyProgressReport $report): bool
    {
        return $this->studentOwns($user, $report);
    }

    public function submit(User $user, WeeklyProgressReport $report): bool
    {
        return $this->studentOwns($user, $report);
    }

    /**
     * Faculty-owner only - see this class's own docblock. Admin never
     * reviews; there is no admin equivalent of this action.
     */
    public function review(User $user, WeeklyProgressReport $report): bool
    {
        return $this->facultyOwns($user, $report);
    }

    private function facultyOwns(User $user, WeeklyProgressReport $report): bool
    {
        return $user->faculty !== null
            && $report->internshipAssignment->faculty_id === $user->faculty->id;
    }

    private function studentOwns(User $user, WeeklyProgressReport $report): bool
    {
        return $user->student !== null
            && $report->internshipAssignment->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
