<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\Auth\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin: put a student's account back on "mobile number + forced change" (route-gated by role:SUPER_ADMIN). */
class StudentAccountController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly PasswordService $passwords) {}

    public function resetPassword(Request $request, Student $student): JsonResponse
    {
        $student->loadMissing('user.student');
        $oneTimePassword = $this->passwords->resetStudentToFirstLogin($student->user, $request->user());

        return $this->success(
            data: ['one_time_password' => $oneTimePassword],
            message: $oneTimePassword
                ? 'No valid mobile number on record, so a one-time password was generated. Share it with the student - it will not be shown again.'
                : 'Password reset. The student can sign in with their JNTU No and registered mobile number, and must then choose a new password.',
        );
    }
}
