<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;

/**
 * Company records are managed by the placement office only. The admin
 * routes are already gated by role:PLACEMENT_ADMIN,SUPER_ADMIN; this
 * policy repeats the check at the object level, same pattern as
 * PlacementDrivePolicy.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
