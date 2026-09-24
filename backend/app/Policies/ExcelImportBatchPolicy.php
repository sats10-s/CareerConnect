<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\ExcelImportBatch;
use App\Models\User;

/**
 * Route-level role gating (role:PLACEMENT_ADMIN,SUPER_ADMIN in
 * routes/api.php) already restricts the Excel Sync Center to admins;
 * this policy is the object-level layer on top, mirroring
 * App\Policies\JobPostingPolicy's pure-role-based pattern exactly
 * (module spec section 33: "Only Admin/Super Admin can access Excel
 * Sync Center" - there is no ownership dimension here at all, unlike
 * InternshipCompletionPolicy/PpoRecordPolicy's faculty-ownership
 * checks, since every import batch is a shared admin-team resource,
 * not one admin's private object).
 *
 * Also covers the two abilities that have no Eloquent instance to
 * check against - export() and template() - by authorizing against
 * the ExcelImportBatch::class string (Gate::authorize('export',
 * ExcelImportBatch::class)), the same "class-level ability" pattern
 * this codebase already uses for viewAny().
 */
class ExcelImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, ExcelImportBatch $batch): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function confirm(User $user, ExcelImportBatch $batch): bool
    {
        return $this->isAdmin($user);
    }

    public function rollback(User $user, ExcelImportBatch $batch): bool
    {
        return $this->isAdmin($user);
    }

    public function export(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function template(User $user): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
