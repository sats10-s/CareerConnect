<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * Student Master Data import - module spec section 17: controlled
 * bulk updates to plain Student fields matched via Roll Number, never
 * via name, and NEVER touching the student's college email (`users.email`
 * - the immutable login identity), only ever `personal_email` and
 * `users.name`.
 */
class ExcelImportStudentMasterTest extends TestCase
{
    use BuildsExcelFixtures;
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
        Storage::fake('local');
        $this->admin = $this->makeAdmin();
    }

    private function headers(): array
    {
        return ['Roll Number', 'Name', 'Department', 'Batch', 'Year', 'Phone', 'Personal Email', 'CGPA', 'Backlogs', 'LinkedIn', 'GitHub', 'Portfolio'];
    }

    private function upload(array $rows): array
    {
        Sanctum::actingAs($this->admin, ['*']);
        $file = $this->makeXlsxUpload($this->headers(), $rows);

        return $this->postJson('/api/admin/excel/import', ['type' => 'STUDENT_MASTER', 'file' => $file])
            ->assertCreated()->json('data');
    }

    public function test_department_batch_and_year_are_updated_by_roll_number(): void
    {
        $studentUser = $this->makeStudentUser(['roll_number' => 'CC26001', 'department_id' => $this->excelDepartment->id, 'batch_id' => $this->excelBatch->id, 'year' => 2]);
        $newDepartment = Department::create(['name' => 'Electronics & Communication', 'code' => 'ECE']);
        $newBatch = Batch::create(['name' => '2023-2027', 'start_year' => 2023, 'end_year' => 2027]);

        $batch = $this->upload([
            ['CC26001', null, $newDepartment->code, $newBatch->name, '3', null, null, null, null, null, null, null],
        ]);

        $this->assertSame(1, $batch['counts']['valid_rows']);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertOk();

        $student = $studentUser->student->fresh();
        $this->assertSame($newDepartment->id, $student->department_id);
        $this->assertSame($newBatch->id, $student->batch_id);
        $this->assertSame(3, $student->year);
    }

    public function test_name_updates_the_user_but_never_touches_the_college_email(): void
    {
        $studentUser = $this->makeStudentUser(['roll_number' => 'CC26002', 'name' => 'Old Name', 'email' => 'protected.email@careerconnect.edu']);

        $batch = $this->upload([
            ['CC26002', 'New Name', null, null, null, null, 'new.personal@example.com', null, null, null, null, null],
        ]);

        $this->assertSame(1, $batch['counts']['valid_rows']);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertOk();

        $studentUser->refresh();
        $this->assertSame('New Name', $studentUser->name);
        $this->assertSame('protected.email@careerconnect.edu', $studentUser->email);
        $this->assertSame('new.personal@example.com', $studentUser->student->fresh()->personal_email);
    }

    public function test_an_unrecognized_department_value_leaves_that_column_unchanged_with_a_message(): void
    {
        $this->makeStudentUser(['roll_number' => 'CC26003', 'name' => 'Old Name']);

        $batch = $this->upload([
            ['CC26003', 'New Name', 'Not A Real Department', null, null, null, null, null, null, null, null, null],
        ]);

        // The Name change is still recognized, so the row is a WARNING
        // (not an ERROR) - but the department is never guessed.
        $this->assertSame(1, $batch['counts']['warning_rows']);

        Sanctum::actingAs($this->admin, ['*']);
        $preview = $this->getJson("/api/admin/excel/imports/{$batch['id']}/preview")->assertOk()->json('data');
        $fields = array_column($preview['items'][0]['changes'], 'field');
        $this->assertContains('name', $fields);
        $this->assertNotContains('department_id', $fields);
    }

    public function test_a_row_with_no_recognized_changes_is_an_error(): void
    {
        $this->makeStudentUser(['roll_number' => 'CC26004']);

        $batch = $this->upload([
            ['CC26004', null, 'Not A Real Department', 'Not A Real Batch', null, null, null, null, null, null, null, null],
        ]);

        $this->assertSame(1, $batch['counts']['error_rows']);
    }

    public function test_an_unmatched_roll_number_never_creates_a_student(): void
    {
        $batch = $this->upload([
            ['CC26999', 'Ghost', null, null, null, null, null, null, null, null, null, null],
        ]);

        $this->assertSame(1, $batch['counts']['error_rows']);
        $this->assertDatabaseCount('students', 0);
    }
}
