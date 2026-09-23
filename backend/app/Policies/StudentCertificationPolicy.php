<?php

namespace App\Policies;

use App\Models\StudentCertification;
use App\Models\User;

/**
 * IDOR guard for /api/student/profile/certifications/{certification}:
 * a student may only update/delete a certification that belongs to
 * THEIR OWN student record.
 */
class StudentCertificationPolicy
{
    public function update(User $user, StudentCertification $certification): bool
    {
        return $this->owns($user, $certification);
    }

    public function delete(User $user, StudentCertification $certification): bool
    {
        return $this->owns($user, $certification);
    }

    private function owns(User $user, StudentCertification $certification): bool
    {
        return $user->student?->id === $certification->student_id;
    }
}
