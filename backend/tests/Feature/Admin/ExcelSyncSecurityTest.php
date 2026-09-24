<?php

namespace Tests\Feature\Admin;

use App\Enums\ExcelImportBatchStatus;
use App\Enums\ExcelImportType;
use App\Models\ExcelImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * Module spec section 33: "Only Admin/Super Admin can access Excel
 * Sync Center." Every route is checked against a real, pre-existing
 * ExcelImportBatch id (never a bare "1" against an empty table) -
 * this codebase's implicit route-model-binding middleware
 * (SubstituteBindings) runs BEFORE the `role:` route middleware, so a
 * non-existent id would 404 before ever reaching the authorization
 * check this test is actually trying to exercise - see the identical
 * `$fixture['document_id']` pattern in
 * tests/Feature/Admin/InternshipDocumentTest.php, which this mirrors.
 */
class ExcelSyncSecurityTest extends TestCase
{
    use BuildsExcelFixtures;
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
        Storage::fake('local');
    }

    private function existingBatchId(): int
    {
        return ExcelImportBatch::create([
            'import_type' => ExcelImportType::STUDENT_MASTER,
            'uploaded_by' => $this->makeAdmin()->id,
            'original_filename' => 'students.xlsx',
            'stored_filename' => 'irrelevant.xlsx',
            'storage_path' => 'excel-imports/irrelevant.xlsx',
            'status' => ExcelImportBatchStatus::READY_FOR_CONFIRMATION,
        ])->id;
    }

    private function routesFor(int $batchId): array
    {
        return [
            'list imports' => ['GET', '/api/admin/excel/imports'],
            'show import' => ['GET', "/api/admin/excel/imports/{$batchId}"],
            'preview' => ['GET', "/api/admin/excel/imports/{$batchId}/preview"],
            'confirm' => ['POST', "/api/admin/excel/imports/{$batchId}/confirm"],
            'rollback' => ['POST', "/api/admin/excel/imports/{$batchId}/rollback"],
            'template' => ['GET', '/api/admin/excel/templates/STUDENT_MASTER'],
            'export' => ['GET', '/api/admin/excel/exports/STUDENTS'],
        ];
    }

    public function test_student_cannot_access_any_excel_sync_center_route(): void
    {
        $batchId = $this->existingBatchId();
        Sanctum::actingAs($this->makeStudentUser(), ['*']);

        foreach ($this->routesFor($batchId) as $label => [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden();
        }
    }

    public function test_faculty_cannot_access_any_excel_sync_center_route(): void
    {
        $batchId = $this->existingBatchId();
        Sanctum::actingAs($this->makeFaculty()->user, ['*']);

        foreach ($this->routesFor($batchId) as $label => [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden();
        }
    }

    public function test_unauthenticated_request_is_rejected_on_every_excel_sync_center_route(): void
    {
        $batchId = $this->existingBatchId();
        $this->app->make('auth')->forgetGuards();

        foreach ($this->routesFor($batchId) as $label => [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_upload_is_rejected_for_a_non_xlsx_file(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $file = UploadedFile::fake()->create('not-excel.txt', 10, 'text/plain');

        $this->postJson('/api/admin/excel/import', ['type' => 'STUDENT_MASTER', 'file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_is_rejected_for_an_unknown_import_type(): void
    {
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $file = $this->makeXlsxUpload(['Roll Number'], [['CC24001']]);

        $this->postJson('/api/admin/excel/import', ['type' => 'NOT_A_REAL_TYPE', 'file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_super_admin_can_reach_the_import_history_endpoint(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);

        $this->getJson('/api/admin/excel/imports')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
