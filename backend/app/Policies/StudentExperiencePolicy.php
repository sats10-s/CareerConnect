<?php

namespace App\Policies;

use App\Models\StudentExperience;
use App\Models\User;

/**
 * IDOR guard for /api/student/profile/experiences/{experience}: a
 * student may only update/delete an experience entry that belongs to
 * THEIR OWN student record.
 */
class StudentExperiencePolicy
{
    public function update(User $user, StudentExperience $experience): bool
    {
        return $this->owns($user, $experience);
    }

    public function delete(User $user, StudentExperience $experience): bool
    {
        return $this->owns($user, $experience);
    }

    private function owns(User $user, StudentExperience $experience): bool
    {
        return $user->student?->id === $experience->student_id;
    }
}
