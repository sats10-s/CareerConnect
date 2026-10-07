<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\ExcelImportRow;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentAliasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsCollegeStudentFile;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\TestCase;

/**
 * Super Admin > Student Data > Placement admins: accounts from an Excel
 * file of Name, Department, College Email, Password - same pipeline as the
 * student import.
 */
class PlacementAdminImportTest extends TestCase
{
    use BuildsCollegeStudentFile;
    use BuildsExcelFixtures;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        RateLimiter::clear('login-ip:127.0.0.1');

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }
        $this->seed(DepartmentAliasSeeder::class);

        $this->superAdmin = User::create([
            'name' => 'Super Admin', 'email' => 'super@careerconnect.edu', 'password' => 'Password@123',
            'role_id' => Role::where('name', RoleName::SUPER_ADMIN->value)->value('id'),
        ]);
        Sanctum::actingAs($this->superAdmin, ['*']);
        // An existing student whose email appears in the file (must be an error, never converted).
        $this->makeStudentAccount();
    }

    /** Header spelling deliberately loose ("Clg Mail", "PASSWORD"). */
    private function adminFile(?array $rows = null): \Illuminate\Http\UploadedFile
    {
        return $this->resultsUpdateFile(['Name', 'Department', 'Clg Mail', 'PASSWORD'], $rows ?? [
            ['Ravi  Kumar', 'CSE', 'Ravi.Kumar@College.edu', 'Ravi@2026'],   // new (email lowercased, name spaces collapsed)
            ['Sita Devi', 'AI&ML', 'sita@college.edu', 'Sita#Pass1'],         // new, department alias
            ['Kiran Rao', 'ECE', 'kiran@college.edu', 123456],                // new, numeric password
            ['Dup Person', 'IT', 'sita@college.edu', 'Another1'],              // error: duplicate email
            ['Bad Email', 'IT', 'not-an-email', 'Password1'],                  // error: invalid email
            ['No Pass', 'IT', 'nopass@college.edu', null],                     // error: blank password
            ['Short Pass', 'IT', 'short@college.edu', 'abc'],                  // error: too short
            ['Robo Admin', 'Robotics', 'robo@college.edu', 'Robotics1'],       // error: unknown department
            ['Student Clash', 'CSE', 'student@college.edu', 'Student123'],     // error: email belongs to a student
        ], 'placement_admins.xlsx');
    }

    private function upload($file): array
    {
        return $this->post('/api/super-admin/student-data/imports', ['type' => 'placement_admins', 'file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
    }

    private function confirm(int $id): array
    {
        $this->postJson("/api/super-admin/student-data/imports/{$id}/confirm")->assertOk();

        return $this->getJson("/api/super-admin/student-data/imports/{$id}")->assertOk()->json('data');
    }

    private function makeStudentAccount(): void
    {
        User::create(['name' => 'A Student', 'email' => 'student@college.edu', 'password' => 'x', 'role_id' => Role::where('name', 'STUDENT')->value('id')]);
    }

    public function test_preview_validates_every_row_and_never_stores_a_plaintext_password(): void
    {
        $batch = $this->upload($this->adminFile());

        $this->assertSame('placement_admins', $batch['type']);
        $this->assertSame(['new' => 3, 'updated' => 0, 'unchanged' => 0, 'error' => 6, 'warning' => 0], $batch['counts']);
        $this->assertSame([['value' => 'ROBOTICS', 'rows' => 1]], $batch['unknown_departments']);

        $messages = collect($this->getJson("/api/super-admin/student-data/imports/{$batch['id']}/rows?tab=errors")->json('data.items'))->pluck('messages')->flatten();
        foreach ([
            'Duplicate college email - already on Worksheet row 3.',
            '"not-an-email" is not a valid email address.',
            'Password is blank.',
            'Password is shorter than 6 characters.',
            'Unknown department "Robotics". Add it (or an alias for it) in Student Data settings, then re-check.',
            "This email already belongs to a Student account, so it can't become a Placement Admin.",
        ] as $expected) {
            $this->assertContains($expected, $messages->all());
        }

        // Nothing stored anywhere in plaintext - not the row data, not the context.
        $stored = ExcelImportRow::where('import_batch_id', $batch['id'])->get()->map(fn ($r) => json_encode([$r->raw_row, $r->confirm_context, $r->messages]))->implode(' ');
        foreach (['Ravi@2026', 'Sita#Pass1', '123456', 'Another1', 'Student123'] as $password) {
            $this->assertStringNotContainsString($password, $stored);
        }
        $this->assertDatabaseCount('users', 2); // super admin + the student; nothing created before confirm
    }

    public function test_confirm_creates_placement_admins_who_sign_in_by_email_and_must_change_their_password(): void
    {
        $batch = $this->confirm($this->upload($this->adminFile())['id']);
        $this->assertSame(['created' => 3, 'updated' => 0, 'failed' => 0, 'one_time_passwords' => 0], $batch['result']);

        $ravi = User::with(['role', 'department'])->where('email', 'ravi.kumar@college.edu')->firstOrFail();
        $this->assertSame('Ravi Kumar', $ravi->name);
        $this->assertSame(RoleName::PLACEMENT_ADMIN, $ravi->roleName());
        $this->assertSame('CSE', $ravi->department->code);
        $this->assertTrue($ravi->must_change_password);
        $this->assertTrue(Hash::check('Ravi@2026', $ravi->password));
        $this->assertSame('AIML', User::with('department')->where('email', 'sita@college.edu')->first()->department->code);
        $this->assertTrue(Hash::check('123456', User::where('email', 'kiran@college.edu')->value('password')));

        // Sign in with the college email and the password from the file...
        auth()->forgetGuards();
        $token = $this->postJson('/api/auth/login', ['login' => 'Ravi.Kumar@college.edu', 'password' => 'Ravi@2026'])
            ->assertOk()->assertJsonPath('data.must_change_password', true)->assertJsonPath('data.role', 'PLACEMENT_ADMIN')->json('token');

        // ...nothing else works until they choose their own password (no personal email needed for staff).
        $this->withToken($token)->getJson('/api/admin/students')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->withToken($token)->postJson('/api/auth/first-login', ['password' => 'MyOwnPass99', 'password_confirmation' => 'MyOwnPass99'])
            ->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->withToken($token)->getJson('/api/admin/students')->assertOk();
    }

    public function test_re_import_updates_name_and_department_but_never_the_password(): void
    {
        $this->confirm($this->upload($this->adminFile())['id']);
        $ravi = User::where('email', 'ravi.kumar@college.edu')->firstOrFail();
        $ravi->forceFill(['password' => Hash::make('ChosenByRavi1'), 'must_change_password' => false, 'password_changed_at' => now()])->save();

        $same = $this->upload($this->adminFile());
        $this->assertSame(['new' => 0, 'updated' => 0, 'unchanged' => 3, 'error' => 6, 'warning' => 0], $same['counts']);
        $this->postJson("/api/super-admin/student-data/imports/{$same['id']}/cancel")->assertOk();

        $batch = $this->upload($this->adminFile([['Ravi Kumar Varma', 'ECE', 'ravi.kumar@college.edu', 'NewFilePass1']]));
        $this->assertSame(1, $batch['counts']['updated']);
        $changes = $this->getJson("/api/super-admin/student-data/imports/{$batch['id']}/rows?tab=updated")->json('data.items.0.changes');
        $this->assertSame(['name', 'department_id'], array_column($changes, 'field'));
        $this->confirm($batch['id']);

        $ravi->refresh();
        $this->assertSame('Ravi Kumar Varma', $ravi->name);
        $this->assertSame(Department::where('code', 'ECE')->value('id'), $ravi->department_id);
        $this->assertTrue(Hash::check('ChosenByRavi1', $ravi->password));
        $this->assertFalse($ravi->must_change_password);
    }

    public function test_rollback_removes_created_admins_until_one_signs_in(): void
    {
        $batch = $this->confirm($this->upload($this->adminFile())['id']);
        $this->assertTrue($batch['can_rollback']);
        $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/rollback")->assertOk()->assertJsonPath('data.result.removed', 3);
        $this->assertSame(0, User::whereHas('role', fn ($q) => $q->where('name', 'PLACEMENT_ADMIN'))->count());

        $second = $this->confirm($this->upload($this->adminFile())['id']);
        User::where('email', 'sita@college.edu')->first()->forceFill(['last_login_at' => now()])->save();
        $this->getJson("/api/super-admin/student-data/imports/{$second['id']}")
            ->assertJsonPath('data.rollback_blocker', '1 Placement Admin(s) from this import have already signed in, so it can no longer be rolled back.');
        $this->postJson("/api/super-admin/student-data/imports/{$second['id']}/rollback")->assertStatus(422);
    }

    public function test_template_has_the_four_columns_and_only_the_super_admin_can_import(): void
    {
        $response = $this->get('/api/super-admin/student-data/templates/placement-admins')->assertOk();
        $sheet = $this->spreadsheetFromResponseContent($response->streamedContent())->getActiveSheet();
        $this->assertSame(['Name', 'Department', 'College Email', 'Password'], $sheet->rangeToArray('A1:D1')[0]);

        // The template itself imports (header row only -> no data).
        Sanctum::actingAs(User::create(['name' => 'PA', 'email' => 'pa@x.test', 'password' => 'x', 'role_id' => Role::where('name', 'PLACEMENT_ADMIN')->value('id')]), ['*']);
        $this->post('/api/super-admin/student-data/imports', ['type' => 'placement_admins', 'file' => $this->adminFile()], ['Accept' => 'application/json'])->assertForbidden();
        $this->get('/api/super-admin/student-data/templates/placement-admins')->assertForbidden();
    }
}
