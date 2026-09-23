<?php

namespace App\Policies;

use App\Models\StudentSkill;
use App\Models\User;

/**
 * A student may only delete their OWN skills. There's no view/update
 * method here - skills only support POST (create) and DELETE (remove);
 * see routes/api.php and the Student Profile module spec ("Add/remove
 * individual skills", not edit-in-place).
 *
 * This is the IDOR guard: the route parameter {skill} resolves to ANY
 * StudentSkill row by id via Laravel's implicit model binding, so
 * without this check a student could delete another student's skill
 * simply by changing the id in the URL. Gate::authorize('delete', ...)
 * in StudentSkillController is what actually invokes this.
 */
class StudentSkillPolicy
{
    public function delete(User $user, StudentSkill $skill): bool
    {
        return $user->student?->id === $skill->student_id;
    }
}
