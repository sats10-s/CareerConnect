<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * The module spec's PRIMARY workflow - Placement Result Import (module
 * spec sections 4-10) - end to end: match by Roll Number (never by
 * name), match Company + Job Role, normalize the Status column via the
 * fixed alias table, validate the transition against
 * ApplicationStatus::canTransitionTo() BEFORE confirmation, and on
 * confirm, write through the real App\Services\ApplicationService
 * (never a raw UPDATE) so history/event dispatch happen exactly as a
 * manual admin status change would.
 */
class ExcelImportApplicationStatusTest extends TestCase
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
        return ['Student Name', 'Roll Number', 'Branch', 'Batch', 'Company', 'Job Role', 'Status', 'Remarks'];
    }

    private function upload(array $rows): array
    {
        Sanctum::actingAs($this->admin, ['*']);
        $file = $this->makeXlsxUpload($this->headers(), $rows);

        return $this->postJson('/api/admin/excel/import', ['type' => 'PLACEMENT_STATUS', 'file' => $file])
            ->assertCreated()
            ->json('data');
    }

    public function test_a_legal_status_transition_matches_and_previews_as_valid(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC24001']);
        $job = $this->makeJobPosting();
        $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW']);

        $batch = $this->upload([
            ['Asha Rao', 'CC24001', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', 'Strong round.'],
        ]);

        $this->assertSame('READY_FOR_CONFIRMATION', $batch['status']);
        $this->assertSame(1, $batch['counts']['total_rows']);
        $this->assertSame(1, $batch['counts']['valid_rows']);
        $this->assertSame(0, $batch['counts']['error_rows']);

        Sanctum::actingAs($this->admin, ['*']);
        $preview = $this->getJson("/api/admin/excel/imports/{$batch['id']}/preview")->assertOk()->json('data');

        $this->assertSame('VALID', $preview['items'][0]['outcome']);
        $this->assertSame('status', $preview['items'][0]['changes'][0]['field']);
        $this->assertSame('UNDER_REVIEW', $preview['items'][0]['changes'][0]['old_value']);
        $this->assertSame('SHORTLISTED', $preview['items'][0]['changes'][0]['new_value']);
    }

    public function test_the_official_templates_starred_required_headers_are_accepted(): void
    {
        // ExcelTemplateService marks required columns with a trailing " *"
        // ("Roll Number *"). A file that keeps those headers - i.e. the
        // downloaded template itself - must import, not fail with
        // "missing required column(s)".
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC24003']);
        $job = $this->makeJobPosting();
        $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW']);

        Sanctum::actingAs($this->admin, ['*']);
        $file = $this->makeXlsxUpload(
            ['Student Name', 'Roll Number *', 'Branch', 'Batch', 'Company *', 'Job Role *', 'Status *', 'Remarks'],
            [['Asha Rao', 'CC24003', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', null]],
        );

        $batch = $this->postJson('/api/admin/excel/import', ['type' => 'PLACEMENT_STATUS', 'file' => $file])
            ->assertCreated()
            ->json('data');

        $this->assertSame('READY_FOR_CONFIRMATION', $batch['status']);
        $this->assertSame(1, $batch['counts']['valid_rows']);
    }

    public function test_confirm_writes_through_application_service_with_full_history(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC24001']);
        $job = $this->makeJobPosting();
        $applicationId = $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW']);

        $batch = $this->upload([
            ['Asha Rao', 'CC24001', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', 'Strong round.'],
        ]);

        Sanctum::actingAs($this->admin, ['*']);
        $result = $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertOk()->json('data.result');

        $this->assertSame(1, $result['applied']);
        $this->assertSame(0, $result['failed']);

        $application = Application::findOrFail($applicationId);
        $this->assertSame('SHORTLISTED', $application->status->value);

        $history = ApplicationStatusHistory::where('application_id', $applicationId)->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertSame('UNDER_REVIEW', $history->from_status->value);
        $this->assertSame('SHORTLISTED', $history->to_status->value);
        $this->assertSame($this->admin->id, $history->changed_by);

        $this->assertDatabaseHas('excel_import_changes', [
            'import_batch_id' => $batch['id'],
            'entity_type' => Application::class,
            'entity_id' => $applicationId,
            'field' => 'status',
            'old_value' => 'UNDER_REVIEW',
            'new_value' => 'SHORTLISTED',
            'changed_by' => $this->admin->id,
        ]);
    }

    public function test_an_unknown_status_value_is_rejected_never_guessed(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC24002']);
        $job = $this->makeJobPosting();
        $this->applyAndAdvance($this->admin, $studentUser, $job, []);

        $batch = $this->upload([
            ['Asha Rao', 'CC24002', null, null, $this->excelCompany->name, $job->title, 'ON HOLD', null],
        ]);

        $this->assertSame(1, $batch['counts']['error_rows']);
        $this->assertSame(0, $batch['counts']['valid_rows']);
    }

    public function test_an_illegal_transition_is_rejected_by_the_same_rules_as_a_manual_change(): void
    {
        // SHORTLISTED -> SELECTED skips the required ASSESSMENT/INTERVIEW
        // step - ApplicationStatus::canTransitionTo() forbids this for a
        // manual admin change, and the importer must forbid it too.
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC24003']);
        $job = $this->makeJobPosting();
        $applicationId = $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW', 'SHORTLISTED']);

        $batch = $this->upload([
            ['Asha Rao', 'CC24003', null, null, $this->excelCompany->name, $job->title, 'SELECTED', null],
        ]);

        $this->assertSame(1, $batch['counts']['error_rows']);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")
            ->assertUnprocessable();

        $this->assertSame('SHORTLISTED', Application::findOrFail($applicationId)->status->value);
    }

    public function test_a_row_that_is_already_at_the_target_status_is_rejected_as_a_no_op(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC24004']);
        $job = $this->makeJobPosting();
        $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW', 'SHORTLISTED']);

        $batch = $this->upload([
            ['Asha Rao', 'CC24004', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', null],
        ]);

        $this->assertSame(1, $batch['counts']['error_rows']);
    }

    public function test_a_row_with_an_unmatched_roll_number_never_creates_anything(): void
    {
        $job = $this->makeJobPosting();

        $batch = $this->upload([
            ['Ghost Student', 'CC24999', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', null],
        ]);

        $this->assertSame(1, $batch['counts']['error_rows']);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * Module spec's own "partial success" design: one bad row in a
     * batch must not block every other valid row from being confirmed.
     */
    public function test_one_bad_row_does_not_block_the_rest_of_the_batch(): void
    {
        $good = $this->makeStudentUser(['name' => 'Asha Rao']);
        $good->student->update(['roll_number' => 'CC24005']);
        $job = $this->makeJobPosting();
        $goodApplicationId = $this->applyAndAdvance($this->admin, $good, $job, ['UNDER_REVIEW']);

        $batch = $this->upload([
            ['Asha Rao', 'CC24005', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', null],
            ['Ghost Student', 'CC24998', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', null],
        ]);

        $this->assertSame(1, $batch['counts']['valid_rows']);
        $this->assertSame(1, $batch['counts']['error_rows']);

        Sanctum::actingAs($this->admin, ['*']);
        $result = $this->postJson("/api/admin/excel/imports/{$batch['id']}/confirm")->assertOk()->json('data');

        $this->assertSame('CONFIRMED', $result['batch']['status']);
        $this->assertSame(1, $result['result']['applied']);
        $this->assertSame('SHORTLISTED', Application::findOrFail($goodApplicationId)->status->value);
    }

    public function test_a_significantly_different_excel_name_produces_a_warning_but_still_matches(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC24006']);
        $job = $this->makeJobPosting();
        $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW']);

        $batch = $this->upload([
            ['Completely Different Name', 'CC24006', null, null, $this->excelCompany->name, $job->title, 'SHORTLISTED', null],
        ]);

        $this->assertSame(1, $batch['counts']['warning_rows']);
        $this->assertSame(0, $batch['counts']['error_rows']);
    }
}
