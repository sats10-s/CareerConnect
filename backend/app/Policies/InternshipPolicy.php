<?php

namespace App\Policies;

use App\Enums\OpportunityStatus;
use App\Enums\RoleName;
use App\Models\Internship;
use App\Models\User;

/**
 * Mirrors JobPostingPolicy exactly - see its docblock.
 */
class InternshipPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Internship $internship): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $internship->status === OpportunityStatus::PUBLISHED;
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Internship $internship): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
