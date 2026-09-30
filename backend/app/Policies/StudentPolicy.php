<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * Placeholder authorization policy. Wires up the app/Policies layer
 * requested by the project architecture; concrete rules (e.g. a
 * faculty member may only view their own mentees, a student may only
 * view their own record) will be filled in as those features are
 * built in a later prompt. For now it prevents IDOR by default -
 * only a student viewing their own record, or staff, are allowed.
 */
class StudentPolicy
{
    /**
     * The institution-wide student directory (GET /api/admin/students) -
     * placement office only. Faculty see just the students assigned to
     * them, through /api/faculty/students.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->roleName()?->value, ['PLACEMENT_ADMIN', 'SUPER_ADMIN'], true);
    }

    /**
     * Full record for the admin directory (profile, applications,
     * assignment, resume download) - placement office only.
     */
    public function viewAsAdmin(User $user, Student $student): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Student $student): bool
    {
        if ($user->id === $student->user_id) {
            return true;
        }

        return in_array($user->roleName()?->value, ['FACULTY', 'PLACEMENT_ADMIN', 'SUPER_ADMIN'], true);
    }
}
