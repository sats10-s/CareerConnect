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
    public function view(User $user, Student $student): bool
    {
        if ($user->id === $student->user_id) {
            return true;
        }

        return in_array($user->roleName()?->value, ['FACULTY', 'PLACEMENT_ADMIN', 'SUPER_ADMIN'], true);
    }
}
