<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** /api/super-admin/* - users, roles, departments, batches, audit log, security and settings. */
class SuperAdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Department $cse;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }
        $this->cse = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
    }

    private function makeUser(RoleName $role, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test '.$role->label(),
            'email' => strtolower($role->value).'-'.uniqid().'@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => Role::where('name', $role->value)->firstOrFail()->id,
        ], $overrides));
    }

    private function actingAsSuperAdmin(): User
    {
        $user = $this->makeUser(RoleName::SUPER_ADMIN);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_only_super_admin_reaches_the_console(): void
    {
        foreach ([RoleName::PLACEMENT_ADMIN, RoleName::FACULTY, RoleName::STUDENT] as $role) {
            Sanctum::actingAs($this->makeUser($role), ['*']);
            foreach (['users', 'roles', 'departments', 'batches', 'audit-logs', 'security', 'settings'] as $path) {
                $this->getJson("/api/super-admin/{$path}")->assertForbidden();
            }
        }
    }

    public function test_create_faculty_and_admin_accounts_with_validation_and_audit(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/super-admin/users', ['name' => 'X', 'email' => 'x@careerconnect.edu', 'role' => 'STUDENT', 'password' => 'Password@123', 'password_confirmation' => 'Password@123'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->postJson('/api/super-admin/users', ['name' => 'Dr. New', 'email' => 'new@careerconnect.edu', 'role' => 'FACULTY', 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertStatus(422)->assertJsonValidationErrors(['password', 'employee_code', 'department_id']);

        $this->postJson('/api/super-admin/users', [
            'name' => 'Dr. New Faculty', 'email' => ' New@CareerConnect.edu ', 'role' => 'FACULTY',
            'password' => 'Password@123', 'password_confirmation' => 'Password@123',
            'employee_code' => 'fac900', 'department_id' => $this->cse->id, 'designation' => 'Professor',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'new@careerconnect.edu')
            ->assertJsonPath('data.role', 'FACULTY')
            ->assertJsonPath('data.faculty.employee_code', 'FAC900')
            ->assertJsonMissingPath('data.password');

        $this->assertTrue(Hash::check('Password@123', User::where('email', 'new@careerconnect.edu')->first()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created']);

        $this->postJson('/api/super-admin/users', ['name' => 'Admin Two', 'email' => 'admin2@careerconnect.edu', 'role' => 'PLACEMENT_ADMIN', 'password' => 'Password@123', 'password_confirmation' => 'Password@123'])
            ->assertCreated()->assertJsonPath('data.faculty', null);

        $this->getJson('/api/super-admin/users?role=FACULTY')->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/super-admin/users?search=admin2')->assertJsonPath('data.items.0.email', 'admin2@careerconnect.edu');
    }

    public function test_deactivate_signs_the_user_out_and_blocks_login_with_guard_rails(): void
    {
        $me = $this->actingAsSuperAdmin();
        $target = $this->makeUser(RoleName::PLACEMENT_ADMIN, ['email' => 'target@careerconnect.edu']);
        $target->createToken('t');

        $this->patchJson("/api/super-admin/users/{$me->id}/status", ['is_active' => false])->assertStatus(422);

        $this->patchJson("/api/super-admin/users/{$target->id}/status", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'subject_id' => $target->id]);

        // Sanctum::actingAs switches the default guard; a real login request runs on the web guard.
        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->postJson('/api/auth/login', ['email' => 'target@careerconnect.edu', 'password' => 'Password@123'])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_blocked']);

        Sanctum::actingAs($me, ['*']);
        $this->patchJson("/api/super-admin/users/{$target->id}/status", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_last_super_admin_cannot_be_demoted_or_deactivated_and_roles_are_locked_for_students_and_faculty(): void
    {
        $me = $this->actingAsSuperAdmin();
        $other = $this->makeUser(RoleName::SUPER_ADMIN);
        $this->patchJson("/api/super-admin/users/{$me->id}", ['role' => 'PLACEMENT_ADMIN'])->assertStatus(422);

        // two super admins: demoting the other is fine, then it would be the last one
        $this->patchJson("/api/super-admin/users/{$other->id}", ['role' => 'PLACEMENT_ADMIN'])->assertOk()->assertJsonPath('data.role', 'PLACEMENT_ADMIN');

        $faculty = $this->makeUser(RoleName::FACULTY);
        Faculty::create(['user_id' => $faculty->id, 'employee_code' => 'FAC1', 'department_id' => $this->cse->id]);
        $this->patchJson("/api/super-admin/users/{$faculty->id}", ['role' => 'SUPER_ADMIN'])->assertStatus(422);
        $this->patchJson("/api/super-admin/users/{$faculty->id}", ['designation' => 'HOD', 'name' => 'Dr. Renamed'])
            ->assertOk()->assertJsonPath('data.faculty.designation', 'HOD')->assertJsonPath('data.name', 'Dr. Renamed');
    }

    public function test_password_reset_and_sign_out_everywhere(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->makeUser(RoleName::PLACEMENT_ADMIN);
        $target->createToken('a');
        $target->createToken('b');

        $this->postJson("/api/super-admin/users/{$target->id}/password", ['password' => 'NewPass@456', 'password_confirmation' => 'Mismatch@1'])->assertStatus(422);
        $this->postJson("/api/super-admin/users/{$target->id}/password", ['password' => 'NewPass@456', 'password_confirmation' => 'NewPass@456'])->assertOk();
        $this->assertTrue(Hash::check('NewPass@456', $target->fresh()->password));
        $this->assertSame(0, $target->tokens()->count());

        $target->createToken('c');
        $this->deleteJson("/api/super-admin/users/{$target->id}/sessions")->assertOk()->assertJsonPath('data.revoked', 1);
    }

    public function test_departments_and_batches_crud_with_in_use_protection(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/super-admin/departments', ['name' => 'Civil Engineering', 'code' => 'civil'])->assertCreated()->assertJsonPath('data.code', 'CIVIL');
        $this->postJson('/api/super-admin/departments', ['name' => 'Civil Engineering', 'code' => 'CE'])->assertStatus(422)->assertJsonValidationErrors('name');
        $civil = Department::where('code', 'CIVIL')->first();
        $this->patchJson("/api/super-admin/departments/{$civil->id}", ['name' => 'Civil & Structural'])->assertOk()->assertJsonPath('data.name', 'Civil & Structural');

        $studentUser = $this->makeUser(RoleName::STUDENT);
        Student::create(['user_id' => $studentUser->id, 'roll_number' => 'CC1', 'department_id' => $this->cse->id, 'batch_id' => $this->batch->id, 'year' => 1]);
        $this->deleteJson("/api/super-admin/departments/{$this->cse->id}")->assertStatus(409);
        $this->deleteJson("/api/super-admin/departments/{$civil->id}")->assertOk();
        $this->getJson('/api/super-admin/departments')->assertOk()->assertJsonPath('data.items.0.students_count', 1);

        $this->postJson('/api/super-admin/batches', ['start_year' => 2026, 'end_year' => 2025])->assertStatus(422)->assertJsonValidationErrors('end_year');
        $this->postJson('/api/super-admin/batches', ['start_year' => 2022, 'end_year' => 2026])->assertStatus(422);
        $this->postJson('/api/super-admin/batches', ['start_year' => 2026, 'end_year' => 2030])->assertCreated()->assertJsonPath('data.name', '2026-2030');
        $this->deleteJson("/api/super-admin/batches/{$this->batch->id}")->assertStatus(409);
        $new = Batch::where('start_year', 2026)->first();
        $this->deleteJson("/api/super-admin/batches/{$new->id}")->assertOk();

        $this->assertSame(5, AuditLog::whereIn('action', ['department.created', 'department.updated', 'department.deleted', 'batch.created', 'batch.deleted'])->count());
    }

    public function test_roles_audit_log_and_security_overview(): void
    {
        $this->makeUser(RoleName::STUDENT, ['email' => 's1@careerconnect.edu']);
        $this->postJson('/api/auth/login', ['email' => 's1@careerconnect.edu', 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 's1@careerconnect.edu', 'password' => 'Password@123'])->assertOk();
        $this->assertNotNull(User::where('email', 's1@careerconnect.edu')->first()->last_login_at);

        $this->actingAsSuperAdmin();
        $roles = $this->getJson('/api/super-admin/roles')->assertOk()->json('data.items');
        $this->assertSame(['STUDENT', 'FACULTY', 'PLACEMENT_ADMIN', 'SUPER_ADMIN'], array_column($roles, 'name'));
        $this->assertSame(1, $roles[0]['users_count']);
        $this->assertNotEmpty($roles[0]['capabilities']);

        $this->getJson('/api/super-admin/audit-logs?action=auth.login_failed')->assertOk()->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.action_label', 'Failed sign-in');
        $this->getJson('/api/super-admin/audit-logs?area=auth')->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/super-admin/audit-logs?action=bogus')->assertStatus(422);

        $this->getJson('/api/super-admin/security')->assertOk()
            ->assertJsonPath('data.stats.failed_logins_24h', 1)
            ->assertJsonPath('data.stats.successful_logins_24h', 1)
            ->assertJsonPath('data.failed_by_account.0.email', 's1@careerconnect.edu');

        $this->getJson('/api/super-admin/dashboard')->assertOk()->assertJsonPath('stats.audit_events', AuditLog::count());
    }

    public function test_settings_update_validate_audit_and_registration_toggle_is_enforced(): void
    {
        $this->actingAsSuperAdmin();
        $this->getJson('/api/super-admin/settings')->assertOk()->assertJsonPath('data.values.allow_student_registration', false);
        $this->patchJson('/api/super-admin/settings', ['allow_student_registration' => true])->assertOk()->assertJsonPath('data.values.allow_student_registration', true);

        $this->patchJson('/api/super-admin/settings', ['support_email' => 'nope', 'attendance_threshold' => 150, 'academic_year' => '26'])
            ->assertStatus(422)->assertJsonValidationErrors(['support_email', 'attendance_threshold', 'academic_year']);
        $this->patchJson('/api/super-admin/settings', ['institution_name' => 'Acme Institute', 'allow_student_registration' => false, 'academic_year' => '2026-27'])
            ->assertOk()->assertJsonPath('data.values.institution_name', 'Acme Institute')->assertJsonPath('data.values.allow_student_registration', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $this->getJson('/api/meta/settings')->assertOk()->assertJsonPath('allow_student_registration', false)->assertJsonPath('institution_name', 'Acme Institute');

        auth()->forgetGuards();
        $this->postJson('/api/auth/register', [
            'name' => 'Late Student', 'email' => 'late@careerconnect.edu', 'password' => 'Password@123', 'password_confirmation' => 'Password@123',
            'roll_number' => 'CC9', 'department_id' => $this->cse->id, 'batch_id' => $this->batch->id, 'year' => 1,
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Student self-registration is currently closed. Contact the placement office.');
        $this->assertDatabaseMissing('users', ['email' => 'late@careerconnect.edu']);
    }

    public function test_attendance_report_defaults_to_the_institution_threshold(): void
    {
        $this->actingAsSuperAdmin();
        $this->getJson('/api/admin/reports/attendance')->assertOk()->assertJsonPath('data.below_threshold.threshold', 75.0);

        $this->patchJson('/api/super-admin/settings', ['attendance_threshold' => 82])->assertOk();
        $this->getJson('/api/admin/reports/attendance')->assertOk()->assertJsonPath('data.below_threshold.threshold', 82.0);
        // An explicit ?threshold= still wins.
        $this->getJson('/api/admin/reports/attendance?threshold=60')->assertOk()->assertJsonPath('data.below_threshold.threshold', 60.0);
    }
}
