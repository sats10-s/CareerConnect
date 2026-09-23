<?php

namespace App\Policies;

use App\Models\StudentProject;
use App\Models\User;

/**
 * IDOR guard for /api/student/profile/projects/{project}: a student may
 * only update/delete a project that belongs to THEIR OWN student record,
 * never one reached by guessing/changing the {project} id in the URL.
 */
class StudentProjectPolicy
{
    public function update(User $user, StudentProject $project): bool
    {
        return $this->owns($user, $project);
    }

    public function delete(User $user, StudentProject $project): bool
    {
        return $this->owns($user, $project);
    }

    private function owns(User $user, StudentProject $project): bool
    {
        return $user->student?->id === $project->student_id;
    }
}
