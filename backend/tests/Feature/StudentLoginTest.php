<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Students sign in with their JNTU No; the first password is their
 * registered mobile number and must be changed before anything else works.
 */
class StudentLoginTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login-ip:127.0.0.1');

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }
        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2023-2027', 'start_year' => 2023, 'end_year' => 2027]);
    }

    /** An imported student: no email, mobile as the first password. */
    private function importedStudent(string $roll = '23341A0501', ?string $phone = '9876500001', array $userOverrides = []): User
    {
        $user = new User(['name' => 'Aarav Test', 'email' => null, 'role_id' => Role::where('name', 'STUDENT')->value('id')]);
        $user->password = Hash::make($phone ?? 'OneTimePass1');
        $user->forceFill(array_merge(['must_change_password' => true, 'credentials_issued_at' => now()], $userOverrides))->save();

        Student::create([
            'user_id' => $user->id, 'roll_number' => $roll, 'department_id' => $this->department->id,
            'batch_id' => $this->batch->id, 'phone' => $phone,
        ]);

        return $user;
    }

    private function staff(RoleName $role, string $email): User
    {
        return User::create(['name' => 'Staff', 'email' => $email, 'password' => Hash::make('Password@123'), 'role_id' => Role::where('name', $role->value)->value('id')]);
    }

    public function test_student_signs_in_with_jntu_no_and_mobile_number(): void
    {
        $this->importedStudent();

        $this->postJson('/api/auth/login', ['login' => '23341a0501 ', 'password' => '9876500001'])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.email', null)
            ->assertJsonStructure(['token']);

        $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => '9876500002'])
            ->assertStatus(422)->assertJsonValidationErrors(['login']);
    }

    public function test_staff_still_sign_in_by_email(): void
    {
        $this->staff(RoleName::PLACEMENT_ADMIN, 'pa@careerconnect.edu');

        $this->postJson('/api/auth/login', ['login' => 'pa@careerconnect.edu', 'password' => 'Password@123'])
            ->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->postJson('/api/auth/login', ['email' => 'pa@careerconnect.edu', 'password' => 'Password@123'])->assertOk();
    }

    public function test_forced_change_user_gets_403_everywhere_until_they_set_a_password(): void
    {
        $user = $this->importedStudent();
        Sanctum::actingAs($user, ['*']);

        foreach (['/api/student/profile', '/api/student/dashboard', '/api/student/opportunities', '/api/student/notifications'] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        }
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);

        // Not the mobile, not the JNTU No, at least 8 characters, plus a personal email.
        $this->postJson('/api/auth/first-login', ['password' => '9876500001', 'password_confirmation' => '9876500001', 'personal_email' => 'a@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/auth/first-login', ['password' => '23341a0501', 'password_confirmation' => '23341a0501', 'personal_email' => 'a@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/auth/first-login', ['password' => 'short', 'password_confirmation' => 'short', 'personal_email' => 'a@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/auth/first-login', ['password' => 'MyNewPass1', 'password_confirmation' => 'MyNewPass1'])
            ->assertStatus(422)->assertJsonValidationErrors(['personal_email']);

        $this->postJson('/api/auth/first-login', ['password' => 'MyNewPass1', 'password_confirmation' => 'MyNewPass1', 'personal_email' => 'aarav@example.com'])
            ->assertOk()->assertJsonPath('data.must_change_password', false);

        $user->refresh();
        $this->assertTrue(Hash::check('MyNewPass1', $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertSame('aarav@example.com', $user->student->personal_email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'subject_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/student/profile')->assertOk();
        // The step can't be repeated once done.
        $this->postJson('/api/auth/first-login', ['password' => 'Another1x', 'password_confirmation' => 'Another1x', 'personal_email' => 'a@example.com'])->assertForbidden();
    }

    public function test_five_failed_attempts_lock_the_account_for_fifteen_minutes(): void
    {
        $user = $this->importedStudent();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => 'wrong'])->assertStatus(422);
        }

        // Locked: even the right password is refused.
        $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => '9876500001'])
            ->assertStatus(429)->assertJsonPath('errors.login.0', 'Too many failed attempts. This account is locked for 15 more minute(s).');
        $this->assertNotNull($user->fresh()->locked_until);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_locked', 'subject_id' => $user->id]);
        $this->assertSame(5, \App\Models\AuditLog::where('action', 'auth.login_failed')->count());

        $this->travel(16)->minutes();
        RateLimiter::clear('login-ip:127.0.0.1');
        $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => '9876500001'])->assertOk();
        $this->assertSame(0, $user->fresh()->failed_logins);
    }

    public function test_per_ip_rate_limit_applies_across_accounts(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/auth/login', ['login' => sprintf('23341A%04d', $i), 'password' => 'x'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['login' => '23341A9999', 'password' => 'x'])->assertStatus(429);
    }

    public function test_unclaimed_first_password_expires_after_30_days_until_an_admin_reset(): void
    {
        $user = $this->importedStudent(userOverrides: ['credentials_issued_at' => now()->subDays(31)]);

        $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => '9876500001'])
            ->assertStatus(422)->assertJsonPath('errors.login.0', 'Your first-time password has expired. Ask the placement office to reset it.');

        $superAdmin = $this->staff(RoleName::SUPER_ADMIN, 'super@careerconnect.edu');
        Sanctum::actingAs($superAdmin, ['*']);
        $this->postJson("/api/super-admin/students/{$user->student->id}/reset-password")->assertOk()->assertJsonPath('data.one_time_password', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.password_reset', 'subject_id' => $user->id, 'actor_id' => $superAdmin->id]);

        auth()->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => '9876500001'])->assertOk();
    }

    public function test_a_password_the_student_set_never_expires(): void
    {
        $this->importedStudent(userOverrides: ['credentials_issued_at' => now()->subDays(90), 'must_change_password' => false, 'password_changed_at' => now()->subDays(80)]);

        $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => '9876500001'])->assertOk();
    }

    public function test_admin_reset_puts_the_student_back_on_mobile_and_forced_change(): void
    {
        $user = $this->importedStudent(userOverrides: ['must_change_password' => false, 'password_changed_at' => now()]);
        $user->forceFill(['password' => Hash::make('TheirOwnPass1')])->save();
        $user->createToken('old');

        $superAdmin = $this->staff(RoleName::SUPER_ADMIN, 'super@careerconnect.edu');
        Sanctum::actingAs($superAdmin, ['*']);
        $this->postJson("/api/super-admin/students/{$user->student->id}/reset-password")->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('9876500001', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertSame(0, $user->tokens()->count());

        // No valid mobile -> a one-time password, returned once.
        $other = $this->importedStudent('23341A0502', null);
        $otp = $this->postJson("/api/super-admin/students/{$other->student->id}/reset-password")->assertOk()->json('data.one_time_password');
        $this->assertNotNull($otp);
        $this->assertTrue(Hash::check($otp, $other->fresh()->password));

        // Placement Admins can't.
        Sanctum::actingAs($this->staff(RoleName::PLACEMENT_ADMIN, 'pa@careerconnect.edu'), ['*']);
        $this->postJson("/api/super-admin/students/{$user->student->id}/reset-password")->assertForbidden();
    }

    public function test_forgot_password_mails_a_link_to_the_personal_email_and_the_token_resets(): void
    {
        Notification::fake();
        $user = $this->importedStudent(userOverrides: ['must_change_password' => false, 'password_changed_at' => now()]);
        $user->student->update(['personal_email' => 'aarav@example.com']);

        $generic = 'If that account has a personal email on file, a reset link is on its way. Check your inbox (and spam folder).';
        $this->postJson('/api/auth/forgot-password', ['login' => '23341a0501'])->assertOk()->assertJsonPath('message', $generic);
        // Unknown accounts get exactly the same answer.
        $this->postJson('/api/auth/forgot-password', ['login' => '99999A9999'])->assertOk()->assertJsonPath('message', $generic);

        $url = null;
        Notification::assertSentOnDemand(PasswordResetLink::class, function (PasswordResetLink $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url) {
            $url = $notification->toMail($notifiable)->actionUrl;

            return $notifiable->routes['mail'] === 'aarav@example.com';
        });
        Notification::assertSentOnDemandTimes(PasswordResetLink::class, 1);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('23341A0501', $query['login']);
        $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $url);

        $this->postJson('/api/auth/reset-password', ['login' => '23341A0501', 'token' => 'nope', 'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123'])
            ->assertStatus(422)->assertJsonValidationErrors(['token']);
        $this->postJson('/api/auth/reset-password', ['login' => '23341A0501', 'token' => $query['token'], 'password' => '9876500001', 'password_confirmation' => '9876500001'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/auth/reset-password', ['login' => '23341A0501', 'token' => $query['token'], 'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123'])
            ->assertOk();

        $this->assertTrue(Hash::check('BrandNew123', $user->fresh()->password));
        // Tokens are single-use.
        $this->postJson('/api/auth/reset-password', ['login' => '23341A0501', 'token' => $query['token'], 'password' => 'Another123', 'password_confirmation' => 'Another123'])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['login' => '23341A0501', 'password' => 'BrandNew123'])->assertOk();
    }
}
