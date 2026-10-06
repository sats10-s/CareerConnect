<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\RoleName;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ExcelImportBatch;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\DepartmentAliasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsCollegeStudentFile;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\TestCase;

/**
 * Super Admin > Student Data > Import, against the synthetic college file
 * (Tests\Concerns\BuildsCollegeStudentFile).
 */
class StudentDataImportTest extends TestCase
{
    use BuildsCollegeStudentFile;
    use BuildsExcelFixtures;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }
        $this->seed(DepartmentAliasSeeder::class);
        AppSetting::create(['key' => 'academic_year', 'value' => '2026-27']);

        $this->superAdmin = User::create([
            'name' => 'Super Admin', 'email' => 'super@careerconnect.edu', 'password' => 'Password@123',
            'role_id' => Role::where('name', RoleName::SUPER_ADMIN->value)->value('id'),
        ]);
        Sanctum::actingAs($this->superAdmin, ['*']);
    }

    private function upload($file, string $type = 'students', array $extra = []): array
    {
        $response = $this->post('/api/super-admin/student-data/imports', ['type' => $type, 'file' => $file] + $extra, ['Accept' => 'application/json']);
        $response->assertCreated();

        return $response->json('data');
    }

    private function confirm(int $batchId): array
    {
        $response = $this->postJson("/api/super-admin/student-data/imports/{$batchId}/confirm");
        $response->assertOk();

        return $this->getJson("/api/super-admin/student-data/imports/{$batchId}")->assertOk()->json('data');
    }

    private function importFixture(): array
    {
        $batch = $this->upload($this->collegeStudentFile());

        return $this->confirm($batch['id']);
    }

    public function test_preview_of_the_college_file_has_exact_counts_and_nothing_is_saved_yet(): void
    {
        $batch = $this->upload($this->collegeStudentFile());

        $this->assertSame('READY_FOR_CONFIRMATION', $batch['status']);
        $this->assertSame(18, $batch['total_rows']);
        $this->assertSame(['new' => 11, 'updated' => 0, 'unchanged' => 0, 'error' => 7, 'warning' => 4], $batch['counts']);

        $sheets = collect($batch['sheets'])->keyBy('name');
        $this->assertSame(12, $sheets['2023 Batch']['rows']);
        $this->assertSame(8, $sheets['2023 Batch']['new']);
        $this->assertSame(4, $sheets['2023 Batch']['error']);
        $this->assertSame(6, $sheets['Lateral & MTech']['rows']);
        $this->assertSame(3, $sheets['Lateral & MTech']['new']);
        $this->assertSame(3, $sheets['Lateral & MTech']['error']);
        $this->assertSame([['value' => 'ROBOTICS', 'rows' => 1]], $batch['unknown_departments']);

        $this->assertDatabaseCount('students', 0);

        $errors = $this->getJson("/api/super-admin/student-data/imports/{$batch['id']}/rows?tab=errors")->assertOk()->json('data.items');
        $messages = collect($errors)->pluck('messages')->flatten()->implode(' | ');
        $this->assertStringContainsString('Section is #N/A.', $messages);
        $this->assertStringContainsString('No JNTU No on this row.', $messages);
        $this->assertStringContainsString('Unknown department "Robotics"', $messages);
        $this->assertStringContainsString('Duplicate JNTU No - already on 2023 Batch row 2.', $messages);
        // M.Tech rows get that one reason only - no department/section noise, no "create department" prompt.
        $mtech = collect($errors)->whereIn('roll_number', ['24341D5701', '24341D5702']);
        $this->assertCount(2, $mtech);
        $this->assertSame([['M.Tech students are not imported.'], ['M.Tech students are not imported.']], $mtech->pluck('messages')->values()->all());

        $warnings = $this->getJson("/api/super-admin/student-data/imports/{$batch['id']}/rows?tab=warnings")->assertOk()->json('data.items');
        $this->assertCount(4, $warnings);
        $this->assertSame(['Divya Test', 'Eshan Test', 'Isha Test', 'Sita Test'], collect($warnings)->pluck('name')->all());
    }

    public function test_confirming_creates_students_with_cleaned_values(): void
    {
        $batch = $this->importFixture();

        $this->assertSame('CONFIRMED', $batch['status']);
        $this->assertSame(['created' => 11, 'updated' => 0, 'failed' => 0, 'one_time_passwords' => 2], $batch['result']);
        $this->assertSame(['done' => 11, 'total' => 11], $batch['progress']);
        $this->assertDatabaseCount('students', 11);

        $aarav = Student::with(['user', 'department', 'batch'])->where('roll_number', '23341A0501')->firstOrFail();
        $this->assertSame('Aarav Test', $aarav->user->name);
        $this->assertNull($aarav->user->email);
        $this->assertSame('A', $aarav->section);                 // "Section A"
        $this->assertSame('male', $aarav->gender->value);         // "M"
        $this->assertSame('9876500001', $aarav->phone);           // typed as a number
        $this->assertSame('Computer Science & Engineering', $aarav->department->name);
        $this->assertSame('2023-2027', $aarav->batch->name);
        $this->assertSame(4, $aarav->year);                       // 2026-27 academic year
        $this->assertSame('8.05', $aarav->cgpa);
        $this->assertSame(0, $aarav->backlogs);
        $this->assertSame('percent', $aarav->ssc_scale->value);
        $this->assertSame('inter', $aarav->inter_type->value);
        $this->assertSame('regular', $aarav->entry_type->value);

        $bhavya = Student::with('user')->where('roll_number', '23341A0502')->firstOrFail();
        $this->assertSame('Bhavya Test', $bhavya->user->name);   // spaces collapsed
        $this->assertSame('9876500002', $bhavya->phone);          // typed as text
        $this->assertSame('cgpa10', $bhavya->ssc_scale->value);
        $this->assertSame('9.80', $bhavya->ssc_score);
        $this->assertSame('female', $bhavya->gender->value);      // "F"

        $chirag = Student::with('department')->where('roll_number', '23341A6601')->firstOrFail();
        $this->assertNull($chirag->backlogs);                     // blank = not reported, never 0
        $this->assertSame('10.00', $chirag->ssc_score);           // "10 POINTS OUT 10"
        $this->assertSame('cgpa10', $chirag->ssc_scale->value);
        $this->assertSame('AIML', $chirag->department->code);

        $divya = Student::with('department')->where('roll_number', '23341A6602')->firstOrFail();
        $this->assertNull($divya->cgpa);                          // 78.5 > 10 -> left empty
        $this->assertSame('B', $divya->section);
        $this->assertSame('AIML', $divya->department->code);      // "AI&ML"

        $eshan = Student::with('department')->where('roll_number', '23341A0101')->firstOrFail();
        $this->assertNull($eshan->phone);                         // 11 digits -> not stored
        $this->assertSame('CIVIL', $eshan->department->code);

        $this->assertSame('MECH', Student::with('department')->where('roll_number', '23341A0301')->first()->department->code); // "ME"
        $this->assertSame('E', Student::where('roll_number', '23341A0302')->value('section'));                                // warned, still saved
        $this->assertSame('AIDS', Student::with('department')->where('roll_number', '23341A5401')->first()->department->code);
        $this->assertSame('9876500012', Student::where('roll_number', '23341A5401')->value('phone'));                         // "98765 00012"
        $this->assertSame('C', Student::where('roll_number', '23341A1201')->value('section'));                                // "c"

        // Laterals: blank Year of Joining -> derived, and they land in the 2023-27 batch.
        $mohan = Student::with('batch')->where('roll_number', '24345A0501')->firstOrFail();
        $this->assertSame(2023, $mohan->year_of_joining);
        $this->assertSame('2023-2027', $mohan->batch->name);
        $this->assertSame('lateral', $mohan->entry_type->value);
        $this->assertSame('diploma', $mohan->inter_type->value);
        $this->assertSame(4, $mohan->year);
        $this->assertSame('2023-2027', Student::with('batch')->where('roll_number', '24345A0401')->first()->batch->name);

        // M.Tech (1D) is out of scope: never imported, and no two-year batch is ever created.
        $this->assertDatabaseMissing('students', ['roll_number' => '24341D5701']);
        $this->assertDatabaseMissing('students', ['roll_number' => '24341D5702']);
        $this->assertSame(['2023-2027'], \App\Models\Batch::pluck('name')->all());

        // History: one initial record per student with a CGPA or backlogs.
        $this->assertDatabaseHas('student_academic_records', ['student_id' => $aarav->id, 'cgpa' => 8.05, 'backlogs' => 0, 'source' => 'import', 'import_id' => $batch['id']]);
    }

    public function test_first_password_is_the_mobile_number_and_only_ever_a_hash(): void
    {
        $this->importFixture();

        $aarav = Student::with('user')->where('roll_number', '23341A0501')->firstOrFail()->user;
        $this->assertTrue($aarav->must_change_password);
        $this->assertNotNull($aarav->credentials_issued_at);
        $this->assertTrue(Hash::check('9876500001', $aarav->password));
        $this->assertNotSame('9876500001', $aarav->getRawOriginal('password'));
        $this->assertStringStartsWith('$2y$', $aarav->getRawOriginal('password'));

        // No users row stores a mobile number in plain text anywhere.
        foreach (User::all() as $user) {
            $this->assertDoesNotMatchRegularExpression('/98765000\d\d/', json_encode($user->getRawOriginal()));
        }
    }

    public function test_one_time_passwords_are_shown_once(): void
    {
        $batch = $this->importFixture();
        $this->assertTrue($batch['has_one_time_passwords']);

        $first = $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/one-time-passwords")->assertOk()->json('data.items');
        $this->assertSame(['23341A0101', '23341A1201'], collect($first)->pluck('roll_number')->sort()->values()->all());

        $eshan = Student::with('user')->where('roll_number', '23341A0101')->firstOrFail()->user;
        $otp = collect($first)->firstWhere('roll_number', '23341A0101')['password'];
        $this->assertTrue(Hash::check($otp, $eshan->password));
        $this->assertTrue($eshan->must_change_password);

        $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/one-time-passwords")->assertOk()->assertJsonPath('data.items', []);
        $this->getJson("/api/super-admin/student-data/imports/{$batch['id']}")->assertJsonPath('data.has_one_time_passwords', false);
    }

    public function test_re_importing_the_same_file_changes_nothing(): void
    {
        $this->importFixture();
        $hashes = User::pluck('password', 'id');

        $batch = $this->upload($this->collegeStudentFile());

        // 0 changes. The three data problems in the file (CGPA 78.5, section E,
        // 11-digit mobile) are still reported as warnings on unchanged rows.
        $this->assertSame(['new' => 0, 'updated' => 0, 'unchanged' => 11, 'error' => 7, 'warning' => 3], $batch['counts']);
        $this->assertSame('VALIDATED', $batch['status']); // nothing to confirm
        $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/confirm")->assertStatus(422);
        $this->assertEquals($hashes, User::pluck('password', 'id'));
    }

    public function test_a_blank_cell_never_erases_and_student_fields_and_passwords_are_never_touched(): void
    {
        $this->importFixture();

        $aarav = Student::with('user')->where('roll_number', '23341A0501')->firstOrFail();
        $aarav->update(['linkedin_url' => 'https://linkedin.com/in/aarav', 'personal_email' => 'aarav@example.com', 'address' => 'Vizag']);
        $aarav->user->forceFill(['password' => 'MyOwnPassword1', 'must_change_password' => false, 'password_changed_at' => now()])->save();
        $ownHash = $aarav->user->fresh()->getRawOriginal('password');

        // Aarav: CGPA, backlogs, mobile, gender, 10th and Inter blank; section changed.
        $rows = $this->regularSheetRows();
        $rows[0] = ['Aarav Test', '23341A0501', 2023, null, 'CSE', 'B', null, null, null, null, null];
        $batch = $this->upload($this->collegeStudentFile($rows));

        $this->assertSame(1, $batch['counts']['updated']);
        $updated = $this->getJson("/api/super-admin/student-data/imports/{$batch['id']}/rows?tab=updated")->json('data.items');
        $this->assertSame([['field' => 'section', 'label' => 'Section', 'old_value' => 'A', 'new_value' => 'B']], $updated[0]['changes']);

        $this->confirm($batch['id']);

        $aarav->refresh();
        $this->assertSame('B', $aarav->section);
        $this->assertSame('8.05', $aarav->cgpa);
        $this->assertSame(0, $aarav->backlogs);
        $this->assertSame('9876500001', $aarav->phone);
        $this->assertSame('male', $aarav->gender->value);
        $this->assertSame('https://linkedin.com/in/aarav', $aarav->linkedin_url);
        $this->assertSame('aarav@example.com', $aarav->personal_email);
        $this->assertSame('Vizag', $aarav->address);
        $this->assertSame($ownHash, $aarav->user->fresh()->getRawOriginal('password'));
    }

    public function test_changed_academics_on_re_import_write_history(): void
    {
        $this->importFixture();

        $rows = $this->regularSheetRows();
        $rows[0][7] = 8.35; // Aarav's CGPA
        $batch = $this->upload($this->collegeStudentFile($rows));
        $this->confirm($batch['id']);

        $aarav = Student::where('roll_number', '23341A0501')->firstOrFail();
        $this->assertSame('8.35', $aarav->cgpa);
        $this->assertDatabaseHas('student_academic_records', ['student_id' => $aarav->id, 'cgpa' => 8.35, 'previous_cgpa' => 8.05, 'import_id' => $batch['id']]);
    }

    public function test_cancel_deletes_the_file_and_saves_nothing(): void
    {
        $batch = $this->upload($this->collegeStudentFile());
        $path = ExcelImportBatch::find($batch['id'])->storage_path;
        Storage::disk('local')->assertExists($path);

        $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('students', 0);
        $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/confirm")->assertStatus(422);
    }

    public function test_confirm_deletes_the_uploaded_file(): void
    {
        $batch = $this->upload($this->collegeStudentFile());
        $path = ExcelImportBatch::find($batch['id'])->storage_path;

        $this->confirm($batch['id']);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_rollback_removes_created_students_until_one_signs_in(): void
    {
        $batch = $this->importFixture();
        $this->assertTrue($batch['can_rollback']);

        $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/rollback")->assertOk()
            ->assertJsonPath('data.result.removed', 11)
            ->assertJsonPath('data.batch.status', 'ROLLED_BACK');
        $this->assertDatabaseCount('students', 0);
        $this->assertSame(1, User::count()); // just the Super Admin

        // A second import: once a student signs in, rollback is refused.
        $second = $this->importFixture();
        Student::with('user')->where('roll_number', '23341A0501')->first()->user->forceFill(['last_login_at' => now()])->save();

        $this->getJson("/api/super-admin/student-data/imports/{$second['id']}")->assertJsonPath('data.can_rollback', false)
            ->assertJsonPath('data.rollback_blocker', '1 student(s) from this import have already signed in or applied, so it can no longer be rolled back.');
        $this->postJson("/api/super-admin/student-data/imports/{$second['id']}/rollback")->assertStatus(422);
        $this->assertDatabaseCount('students', 11);
    }

    public function test_recheck_picks_up_a_newly_added_department_alias(): void
    {
        $batch = $this->upload($this->collegeStudentFile());
        $robotics = $this->postJson('/api/super-admin/departments', ['name' => 'Robotics Engineering', 'code' => 'ROBO'])->assertCreated()->json('data.id');
        $this->postJson('/api/super-admin/student-data/department-aliases', ['alias' => 'Robotics', 'department_id' => $robotics])->assertCreated();

        $rechecked = $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/recheck")->assertOk()->json('data');

        $this->assertSame(12, $rechecked['counts']['new']);
        $this->assertSame(6, $rechecked['counts']['error']);
        $this->assertSame([], $rechecked['unknown_departments']);
    }

    public function test_issues_download_lists_every_error_and_warning(): void
    {
        $batch = $this->upload($this->collegeStudentFile());

        $response = $this->get("/api/super-admin/student-data/imports/{$batch['id']}/issues");
        $response->assertOk();
        $sheet = $this->spreadsheetFromResponseContent($response->streamedContent())->getActiveSheet();

        $this->assertSame(['Sheet', 'Row', 'JNTU No', 'Name', 'Type', 'Problem'], $sheet->rangeToArray('A1:F1')[0]);
        $rows = collect($sheet->toArray())->slice(1);
        $this->assertCount(7, $rows->where(4, 'Error (row skipped)'));
        $this->assertTrue($rows->contains(fn ($r) => $r[0] === 'Lateral & MTech' && $r[1] == 6 && $r[5] === 'Section is #N/A.'));
    }

    public function test_a_cgpa_column_full_of_percentages_is_one_sheet_note_not_a_warning_per_row(): void
    {
        $rows = $this->regularSheetRows();
        foreach ($rows as $i => $row) {
            $rows[$i][7] = 60 + $i * 2.5; // the college's 2024 sheet: percentages in the CGPA column
        }

        $batch = $this->upload($this->collegeStudentFile($rows));

        $sheet = collect($batch['sheets'])->firstWhere('name', '2023 Batch');
        $this->assertSame('The CGPA column looks like percentages (12 of 12 values are above 10), so those CGPAs were left empty. Send real CGPAs later as a Results update.', $sheet['note']);
        $this->assertNull(collect($batch['sheets'])->firstWhere('name', 'Lateral & MTech')['note']);
        // Only the other problems remain as warnings: Eshan (mobile), Isha (section), Sita (no mobile).
        $this->assertSame(3, $batch['counts']['warning']);
        $this->assertSame(11, $batch['counts']['new']);

        $this->confirm($batch['id']);
        $this->assertNull(Student::where('roll_number', '23341A0501')->value('cgpa'));
    }

    public function test_audit_entries_carry_counts_only(): void
    {
        $this->importFixture();

        $entries = AuditLog::query()->where('action', 'like', 'student.import_%')->get();
        $this->assertSame(['student.import_uploaded', 'student.import_confirmed'], $entries->pluck('action')->all());
        foreach ($entries as $entry) {
            $this->assertDoesNotMatchRegularExpression('/\d{5}[A-Z]\d{4}|Test/', json_encode($entry->metadata).$entry->description);
        }
    }

    public function test_only_the_super_admin_can_import_and_the_excel_sync_center_never_sees_these_imports(): void
    {
        $batch = $this->upload($this->collegeStudentFile());

        $placementAdmin = User::create(['name' => 'PA', 'email' => 'pa@x.test', 'password' => 'x', 'role_id' => Role::where('name', 'PLACEMENT_ADMIN')->value('id')]);
        Sanctum::actingAs($placementAdmin, ['*']);

        $this->getJson('/api/super-admin/student-data/imports')->assertForbidden();
        $this->post('/api/super-admin/student-data/imports', ['type' => 'students', 'file' => $this->collegeStudentFile()], ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson('/api/admin/excel/imports')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/admin/excel/imports/{$batch['id']}")->assertNotFound();
        $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertNotFound();
        $this->post('/api/admin/excel/import', ['type' => 'STUDENTS', 'file' => $this->collegeStudentFile()], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_results_update_changes_only_cgpa_and_backlogs_with_history_and_notification(): void
    {
        $this->importFixture();

        $file = $this->resultsUpdateFile(['JNTU No', 'Name', 'CGPA', 'No. of Backlogs', 'Semester', 'Remarks'], [
            ['23341A0501', 'Aarav Test', 8.4, 0, '3-2', 'ignored'],     // CGPA up
            ['23341A0502', 'Bhavya Test', null, 0, '3-2', null],         // backlogs cleared, CGPA blank kept
            ['23341A6601', 'Chirag Test', 8.9, null, '3-2', null],       // unchanged
            ['23341A0101', 'Eshan Test', 9.2, 2, '3-2', null],           // +2.3 -> flagged
            ['99999A9999', 'Nobody', 7.0, 0, '3-2', null],               // unknown student
            ['24341D5701', 'Om Test', 8.1, 0, '3-2', null],              // M.Tech - not imported
        ]);

        $batch = $this->upload($file, 'academic_update');
        $this->assertSame(['new' => 0, 'updated' => 3, 'unchanged' => 1, 'error' => 2, 'warning' => 1], $batch['counts']);

        $flagged = $this->getJson("/api/super-admin/student-data/imports/{$batch['id']}/rows?tab=flagged")->json('data.items');
        $this->assertSame('23341A0101', $flagged[0]['roll_number']);
        $this->assertTrue($flagged[0]['flagged']);

        $this->confirm($batch['id']);

        $aarav = Student::where('roll_number', '23341A0501')->firstOrFail();
        $this->assertSame('8.40', $aarav->cgpa);
        $this->assertNotNull($aarav->academics_updated_at);
        $bhavya = Student::where('roll_number', '23341A0502')->firstOrFail();
        $this->assertSame('7.50', $bhavya->cgpa);
        $this->assertSame(0, $bhavya->backlogs);

        $this->assertDatabaseHas('student_academic_records', [
            'student_id' => $aarav->id, 'cgpa' => 8.4, 'previous_cgpa' => 8.05, 'semester_label' => '3-2',
            'source' => 'import', 'import_id' => $batch['id'], 'changed_by' => $this->superAdmin->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $aarav->user_id,
            'type' => 'ACADEMIC_RECORD_UPDATED',
            'message' => 'Your academic record was updated: CGPA 8.40, backlogs 0.',
        ]);

        // Rolling back a results update restores the old numbers, with history.
        $this->postJson("/api/super-admin/student-data/imports/{$batch['id']}/rollback")->assertOk();
        $this->assertSame('8.05', $aarav->fresh()->cgpa);
        $this->assertSame(1, $bhavya->fresh()->backlogs);
    }
}
