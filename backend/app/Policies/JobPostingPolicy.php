<?php

namespace App\Policies;

use App\Enums\OpportunityStatus;
use App\Enums\RoleName;
use App\Models\JobPosting;
use App\Models\User;

/**
 * Route-level role gating (role:PLACEMENT_ADMIN,SUPER_ADMIN /
 * role:STUDENT in routes/api.php) already restricts *which controller*
 * a request can reach; this policy is the second, object-level check
 * inside those controllers - in particular it's what stops a student
 * from viewing a draft/closed/archived posting just by guessing its
 * id (see Api\Student\OpportunityController::showJob).
 */
class JobPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobPosting $jobPosting): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        // Students only see postings from APPROVED companies: a pending,
        // rejected or suspended company's postings stay hidden even when published.
        return $jobPosting->status === OpportunityStatus::PUBLISHED
            && $jobPosting->company()->approved()->exists();
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, JobPosting $jobPosting): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
