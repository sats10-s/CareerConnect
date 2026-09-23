<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\InternshipAssignment;
use App\Models\PpoRecord;
use App\Models\User;

/**
 * Object-level authorization for Internship Completion & PPO's PPO
 * records - mirrors InternshipCompletionPolicy exactly (same
 * FACULTY-writes / STUDENT-view-own / ADMIN-view-only shape). See that
 * class's own docblock for the full reasoning; kept as its own policy
 * class (rather than folding into InternshipCompletionPolicy) since
 * ppo_records and internship_completions are two distinct
 * models/resources, matching this codebase's one-policy-per-model
 * convention.
 */
class PpoRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $user->roleName() === RoleName::FACULTY || $user->roleName() === RoleName::STUDENT;
    }

    public function view(User $user, PpoRecord $ppoRecord): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->facultyOwns($user, $ppoRecord->internshipAssignment)) {
            return true;
        }

        return $this->studentOwns($user, $ppoRecord->internshipAssignment);
    }

    /**
     * $assignment is the InternshipAssignment a PPO record would be
     * created against - passed explicitly (via
     * `Gate::authorize('create', [PpoRecord::class, $assignment])`)
     * since no PpoRecord instance exists yet at creation time.
     * Faculty-only.
     */
    public function create(User $user, InternshipAssignment $assignment): bool
    {
        return $this->facultyOwns($user, $assignment);
    }

    /**
     * Faculty-owner only, AND the record must not already be terminal
     * (ACCEPTED/DECLINED) - re-checked again in
     * InternshipCompletionService::updatePpo() as the load-bearing
     * check.
     */
    public function update(User $user, PpoRecord $ppoRecord): bool
    {
        return $this->facultyOwns($user, $ppoRecord->internshipAssignment) && ! $ppoRecord->status->isTerminal();
    }

    private function facultyOwns(User $user, InternshipAssignment $assignment): bool
    {
        return $user->faculty !== null && $assignment->faculty_id === $user->faculty->id;
    }

    private function studentOwns(User $user, InternshipAssignment $assignment): bool
    {
        return $user->student !== null && $assignment->student_id === $user->student->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->roleName(), [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true);
    }
}
