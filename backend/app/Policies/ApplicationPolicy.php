<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Application;
use App\Models\User;

/**
 * Object-level authorization for the Application Lifecycle module,
 * layered under the route-level role:STUDENT / role:PLACEMENT_ADMIN,
 * SUPER_ADMIN gates in routes/api.php (see JobPostingPolicy's docblock
 * for why both layers exist). owns() is the critical IDOR/BOLA guard:
 * a student can only ever view or withdraw an application whose
 * student_id matches their OWN Student row - callers always derive
 * that row from $request->user()->student, never from the URL/request
 * (see Api\Student\ApplicationController), so a student can never
 * reach another student's application just by changing the {application}
 * id in the URL.
 */
class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Application $application): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->owns($user, $application);
    }

    public function withdraw(User $user, Application $application): bool
    {
        return $this->owns($user, $application);
    }

    public function updateStatus(User $user, Application $application): bool
    {
        return $this->isAdmin($user);
    }

    private function owns(User $user, Application $application): bool
    {
        return $user->student !== null && $application->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
