<?php

namespace App\Policies;

use App\Enums\PlacementDriveStatus;
use App\Enums\RoleName;
use App\Models\PlacementDrive;
use App\Models\User;

/**
 * Route-level role gating (role:PLACEMENT_ADMIN,SUPER_ADMIN /
 * role:STUDENT in routes/api.php) already restricts *which controller*
 * a request can reach; this policy is the second, object-level check
 * inside those controllers - in particular it's what stops a student
 * from viewing a draft/closed/cancelled drive just by guessing its id
 * (see Api\Student\PlacementDriveController::show), the same role this
 * pattern already plays for JobPostingPolicy/InterviewPolicy.
 *
 * Faculty has no grant here - nothing in this app's existing
 * requirements gives Faculty placement-drive management, so `isAdmin()`
 * stays PLACEMENT_ADMIN/SUPER_ADMIN only, same as every other admin
 * policy in this codebase.
 */
class PlacementDrivePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, PlacementDrive $placementDrive): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $placementDrive->status === PlacementDriveStatus::PUBLISHED;
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Reused for every admin write action on an existing drive -
     * update, publish, close, cancel, attach/detach opportunity - all
     * identical "admin only" checks, the same minimal-ability-set
     * pattern InterviewPolicy::update() already established.
     */
    public function update(User $user, PlacementDrive $placementDrive): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
