<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;

/**
 * Company records are managed by the placement office. The admin routes
 * are already gated by role:PLACEMENT_ADMIN,SUPER_ADMIN; this policy
 * repeats the check at the object level, same pattern as
 * PlacementDrivePolicy. Approval decisions are Super Admin only (review),
 * and a recruiter may only touch its own company (manageOwn).
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

    /** Approve / reject / suspend / reactivate: Super Admin only, never Placement Admin. */
    public function review(User $user, ?Company $company = null): bool
    {
        return $user->roleName() === RoleName::SUPER_ADMIN;
    }

    /** A COMPANY login reading or editing its own company - and only its own. */
    public function manageOwn(User $user, Company $company): bool
    {
        return $user->roleName() === RoleName::COMPANY
            && $user->company_id !== null
            && $user->company_id === $company->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
