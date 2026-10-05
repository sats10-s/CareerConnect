<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Interview;
use App\Models\User;

/**
 * Object-level authorization for Interview Management, layered under
 * the route-level role:STUDENT / role:PLACEMENT_ADMIN,SUPER_ADMIN
 * gates in routes/api.php - same pattern as ApplicationPolicy (see its
 * docblock). owns() is the IDOR/BOLA guard: ownership is always
 * derived by walking interview -> application -> student, and a
 * student can only ever view an interview whose application belongs
 * to their OWN Student row - never trusted from the URL/request.
 */
class InterviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Interview $interview): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->owns($user, $interview);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Interview $interview): bool
    {
        return $this->isAdmin($user);
    }

    /** A recruiter handling an interview for one of its own company's postings. */
    public function manageAsCompany(User $user, Interview $interview): bool
    {
        return $user->roleName() === RoleName::COMPANY
            && $user->company_id !== null
            && $interview->application?->applicable?->company_id === $user->company_id;
    }

    private function owns(User $user, Interview $interview): bool
    {
        return $user->student !== null
            && $interview->application !== null
            && $interview->application->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
