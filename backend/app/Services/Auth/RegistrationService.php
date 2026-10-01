<?php

namespace App\Services\Auth;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Encapsulates the business logic for turning a validated registration
 * payload into a User + Student record. Kept out of the controller so
 * the HTTP layer stays a thin adapter, per the project's "no business
 * logic in controllers" rule.
 */
class RegistrationService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, roll_number: string, department_id: int, batch_id: int, year: int, phone?: string|null}  $data
     */
    public function registerStudent(array $data): User
    {
        // Super Admin > Settings can close self-registration (accounts then come from Excel Sync imports).
        if (! $this->settings->get('allow_student_registration')) {
            $this->audit->record('auth.registration_blocked', 'Registration attempt while self-registration is closed', null, ['email' => $data['email'] ?? null]);

            throw ValidationException::withMessages([
                'email' => ['Student self-registration is currently closed. Contact the placement office.'],
            ]);
        }

        $user = DB::transaction(function () use ($data) {
            $studentRole = Role::where('name', RoleName::STUDENT->value)->firstOrFail();

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role_id' => $studentRole->id,
            ]);

            Student::create([
                'user_id' => $user->id,
                'roll_number' => $data['roll_number'],
                'department_id' => $data['department_id'],
                'batch_id' => $data['batch_id'],
                'year' => $data['year'],
                'phone' => $data['phone'] ?? null,
            ]);

            return $user->load(['role', 'student.department', 'student.batch']);
        });

        $this->audit->record('auth.registered', "{$user->name} registered as a student", $user, [], $user);

        return $user;
    }
}
