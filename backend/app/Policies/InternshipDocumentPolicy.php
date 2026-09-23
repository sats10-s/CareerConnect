<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\InternshipAssignment;
use App\Models\InternshipDocument;
use App\Models\User;

/**
 * Object-level authorization for Internship Document Management,
 * layered under the route-level role:PLACEMENT_ADMIN,SUPER_ADMIN /
 * role:FACULTY / role:STUDENT gates in routes/api.php - same pattern
 * as WeeklyProgressReportPolicy (see its docblock).
 *
 * Every ownership check is resolved by walking InternshipDocument ->
 * internshipAssignment -> faculty_id/student_id - never a direct
 * student_id/faculty_id column on InternshipDocument itself (there
 * isn't one), and never the authenticated User's own id (a
 * Faculty/Student row's primary key is its own id, distinct from
 * user_id - see InternshipAssignmentPolicy::owns()'s docblock, which
 * this mirrors).
 *
 * STUDENT gets create()/resubmit()/view()/download() - but only
 * against their OWN assignment/document - never review(). FACULTY gets
 * view()/download()/review() against their OWN assigned students'
 * documents; never create()/resubmit() - a faculty member can never
 * upload or replace a student's own file, only move it through the
 * review lifecycle (per the module spec's "Faculty cannot replace
 * uploaded files"). PLACEMENT_ADMIN/SUPER_ADMIN get read-only access
 * (viewAny()/view()/download()) - there is no admin review()/create()/
 * resubmit() at all, per the module spec's "Do NOT give admin an
 * unrestricted ability to alter student-owned documents".
 */
class InternshipDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $user->roleName() === RoleName::FACULTY || $user->roleName() === RoleName::STUDENT;
    }

    public function view(User $user, InternshipDocument $document): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->facultyOwns($user, $document)) {
            return true;
        }

        return $this->studentOwns($user, $document);
    }

    /**
     * Downloading the physical file is authorized identically to
     * viewing its metadata - there is no path to reach the file's
     * bytes that isn't also a path that can see the document exists.
     */
    public function download(User $user, InternshipDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * $assignment is the InternshipAssignment a document would be
     * uploaded against - passed explicitly (via
     * `Gate::authorize('create', [InternshipDocument::class, $assignment])`)
     * since no InternshipDocument instance exists yet at upload time.
     * Student-only - see this class's own docblock.
     */
    public function create(User $user, InternshipAssignment $assignment): bool
    {
        return $user->roleName() === RoleName::STUDENT
            && $user->student !== null
            && $assignment->student_id === $user->student->id;
    }

    /**
     * Replacing the file - student-owner only. Faculty NEVER gets
     * resubmit() access (see this class's own docblock); a faculty
     * member's only write path is review().
     */
    public function resubmit(User $user, InternshipDocument $document): bool
    {
        return $this->studentOwns($user, $document);
    }

    /**
     * Faculty-owner only - see this class's own docblock. Admin never
     * reviews; there is no admin equivalent of this action.
     */
    public function review(User $user, InternshipDocument $document): bool
    {
        return $this->facultyOwns($user, $document);
    }

    private function facultyOwns(User $user, InternshipDocument $document): bool
    {
        return $user->faculty !== null
            && $document->internshipAssignment->faculty_id === $user->faculty->id;
    }

    private function studentOwns(User $user, InternshipDocument $document): bool
    {
        return $user->student !== null
            && $document->internshipAssignment->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
