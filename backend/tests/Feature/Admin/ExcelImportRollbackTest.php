<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\ExcelImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * Module spec section 13 - rollback of a CONFIRMED import, through the
 * same domain services as the original change, always itself audited.
 *
 * IMPORTANT, and disclosed prominently in the final delivery report:
 * every status enum this module writes to (ApplicationStatus,
 * InternshipAssignmentStatus, PpoStatus, InterviewStatus) is a
 * strictly forward-only graph in this codebase - no B->A edge exists
 * for any A->B edge any of them define. That means a REVERTED outcome
 * is only realistically reachable for Student Master's plain scalar
 * field changes; a status-field rollback will correctly, and
 * deliberately, fall through to CONFLICT every time under this
 * codebase's actual business rules - see
 * App\Services\ExcelImportService::rollback()'s own docblock. Both
 * behaviours are asserted here so a future change to either the
 * transition graphs or the rollback logic is caught by this suite.
 */
class ExcelImportRollbackTest extends TestCase
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

    private function confirmApplicationStatusImport(string $rollNumber, string $studentName, string $company, string $role, string $status): array
    {
        Sanctum::actingAs($this->admin, ['*']);
        $file = $this->makeXlsxUpload(
            ['Student Name', 'Roll Number', 'Branch', 'Batch', 'Company', 'Job Role', 'Status', 'Remarks'],
            [[$studentName, $rollNumber, null, null, $company, $role, $status, null]],
        );

        $batch = $this->postJson('/api/admin/excel/import', ['type' => 'PLACEMENT_STATUS', 'file' => $file])
            ->assertCreated()->json('data');

        $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertOk();

        return $batch;
    }

    public function test_rolling_back_an_application_status_change_reports_conflict_not_reverted(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao', 'roll_number' => 'CC27001']);
        $job = $this->makeJobPosting();
        $applicationId = $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW']);

        $batch = $this->confirmApplicationStatusImport('CC27001', 'Asha Rao', $this->excelCompany->name, $job->title, 'SHORTLISTED');

        Sanctum::actingAs($this->admin, ['*']);
        $result = $this->postJson("/api/admin/excel/imports/{$batch['id']}/rollback")->assertOk()->json('data');

        $this->assertSame(0, $result['result']['reverted']);
        $this->assertSame(1, $result['result']['conflicts']);
        // Nothing reverted -> the application must still hold the
        // status this import set, untouched.
        $this->assertSame('SHORTLISTED', Application::findOrFail($applicationId)->status->value);
        // A partial (here: total) rollback failure never claims
        // ROLLED_BACK - the batch stays CONFIRMED for manual review.
        $this->assertSame('CONFIRMED', ExcelImportBatch::findOrFail($batch['id'])->status->value);
    }

    public function test_a_manual_change_since_the_import_is_also_reported_as_conflict(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao', 'roll_number' => 'CC27002']);
        $job = $this->makeJobPosting();
        $applicationId = $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW']);

        $batch = $this->confirmApplicationStatusImport('CC27002', 'Asha Rao', $this->excelCompany->name, $job->title, 'SHORTLISTED');

        // Module spec's own example: something else changes the record
        // after our import, before anyone rolls it back.
        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/applications/{$applicationId}/status", ['status' => 'ASSESSMENT'])->assertOk();

        $result = $this->postJson("/api/admin/excel/imports/{$batch['id']}/rollback")->assertOk()->json('data.result');

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame('ASSESSMENT', Application::findOrFail($applicationId)->status->value);
    }

    public function test_rolling_back_a_student_master_field_change_actually_reverts_it(): void
    {
        $studentUser = $this->makeStudentUser(['roll_number' => 'CC27003', 'name' => 'Old Name']);

        Sanctum::actingAs($this->admin, ['*']);
        $file = $this->makeXlsxUpload(
            ['Roll Number', 'Name', 'Department', 'Batch', 'Year', 'Phone', 'Personal Email', 'CGPA', 'Backlogs', 'LinkedIn', 'GitHub', 'Portfolio'],
            [['CC27003', 'New Name', null, null, null, null, null, null, null, null, null, null]],
        );
        $batch = $this->postJson('/api/admin/excel/import', ['type' => 'STUDENT_MASTER', 'file' => $file])
            ->assertCreated()->json('data');
        $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertOk();

        $this->assertSame('New Name', $studentUser->fresh()->name);

        $result = $this->postJson("/api/admin/excel/imports/{$batch['id']}/rollback")->assertOk()->json('data');

        $this->assertSame(1, $result['result']['reverted']);
        $this->assertSame(0, $result['result']['conflicts']);
        $this->assertSame('ROLLED_BACK', $result['batch']['status']);
        $this->assertSame('Old Name', $studentUser->fresh()->name);
    }

    public function test_a_freshly_created_record_is_reported_as_not_rollbackable(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture($this->admin);
        $fixture['student']->student->update(['roll_number' => 'CC27004']);

        Sanctum::actingAs($this->admin, ['*']);
        $file = $this->makeXlsxUpload(
            ['Roll Number', 'Student Name', 'Internship', 'Attendance Date', 'Status', 'Check In', 'Check Out'],
            [['CC27004', $fixture['student']->name, $fixture['internship']->title, now()->toDateString(), 'PRESENT', '09:30', '18:00']],
        );
        $batch = $this->postJson('/api/admin/excel/import', ['type' => 'ATTENDANCE', 'file' => $file])
            ->assertCreated()->json('data');
        $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertOk();

        $result = $this->postJson("/api/admin/excel/imports/{$batch['id']}/rollback")->assertOk()->json('data.result');

        $this->assertSame(0, $result['reverted']);
        $this->assertSame(1, $result['not_rollbackable']);
        $this->assertDatabaseCount('attendance', 1);
    }

    public function test_only_a_confirmed_batch_can_be_rolled_back(): void
    {
        $batch = ExcelImportBatch::create([
            'import_type' => 'STUDENT_MASTER',
            'uploaded_by' => $this->admin->id,
            'original_filename' => 'x.xlsx',
            'stored_filename' => 'x.xlsx',
            'storage_path' => 'excel-imports/x.xlsx',
            'status' => 'READY_FOR_CONFIRMATION',
        ]);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/excel/imports/{$batch->id}/rollback")->assertUnprocessable();
    }
}
