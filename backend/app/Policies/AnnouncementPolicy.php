<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Announcement;
use App\Models\User;

/**
 * Object-level authorization for the Announcements module, layered
 * under the route-level role:STUDENT / role:FACULTY /
 * role:PLACEMENT_ADMIN,SUPER_ADMIN gates in routes/api.php. Management
 * abilities (create/update/publish/archive) are admin-only, per the
 * module spec's explicit "do not allow Faculty to manage announcements
 * unless existing RBAC explicitly grants it" - nothing in this
 * codebase's existing role model grants Faculty any management
 * ability anywhere, so none is added here either. view() is the
 * IDOR/audience guard for a direct-by-id fetch: an admin may always
 * view any announcement (draft, archived, any audience - needed to
 * manage it at all), but a student/faculty member may only ever view
 * one that is currently PUBLISHED, past its publish_at, not yet past
 * its expires_at, AND addressed to their role - see
 * App\Models\Announcement::isVisibleTo() for that exact combined rule.
 */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        // Every authenticated role may list announcements - a
        // student/faculty listing is audience/active-scoped by
        // App\Services\AnnouncementService::activeFor() itself, not by
        // this ability check (mirrors the route-level role gates,
        // which already restrict WHICH controller/index a given role
        // can even reach).
        return true;
    }

    public function view(User $user, Announcement $announcement): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $announcement->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $this->isAdmin($user);
    }

    public function publish(User $user, Announcement $announcement): bool
    {
        return $this->isAdmin($user);
    }

    public function archive(User $user, Announcement $announcement): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
