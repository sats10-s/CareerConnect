<?php

namespace Tests\Feature\Admin;

use App\Enums\ExcelExportType;
use App\Enums\ExcelImportType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * Templates (module spec section 18) and raw/analytical exports
 * (module spec sections 19-31). Every response is read back with
 * PhpSpreadsheet (App\Services\ExcelTemplateService/ExcelExportService's
 * own writer) rather than just checked for a 200 status, so a broken
 * workbook (e.g. a column count mismatch) fails these tests instead of
 * silently downloading a corrupt file.
 */
class ExcelExportTemplateTest extends TestCase
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

    public static function importTypes(): array
    {
        return array_map(fn (string $v) => [$v], ExcelImportType::values());
    }

    /**
     * @dataProvider importTypes
     */
    public function test_every_import_type_has_a_downloadable_template_with_a_header_row(string $type): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->get('/api/admin/excel/templates/'.$type);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $spreadsheet = $this->spreadsheetFromResponseContent($response->streamedContent());
        $headerRow = $spreadsheet->getActiveSheet()->rangeToArray('A1:Z1')[0];
        $headerRow = array_values(array_filter($headerRow, fn ($v) => $v !== null && $v !== ''));

        $this->assertNotEmpty($headerRow);
    }

    public function test_an_unknown_template_type_returns_404(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/admin/excel/templates/NOT_A_TYPE')->assertNotFound();
    }

    public static function rawExportTypes(): array
    {
        return array_map(fn (ExcelExportType $t) => [$t->value], array_filter(ExcelExportType::cases(), fn ($t) => $t->isRaw()));
    }

    /**
     * @dataProvider rawExportTypes
     */
    public function test_every_raw_export_type_downloads_a_readable_workbook(string $type): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->get('/api/admin/excel/exports/'.$type);
        $response->assertOk();

        $spreadsheet = $this->spreadsheetFromResponseContent($response->streamedContent());
        $this->assertNotEmpty($spreadsheet->getActiveSheet()->rangeToArray('A1:Z1')[0]);
    }

    public static function analyticalExportTypes(): array
    {
        return array_map(fn (ExcelExportType $t) => [$t->value], array_filter(ExcelExportType::cases(), fn ($t) => $t->isAnalytical()));
    }

    /**
     * @dataProvider analyticalExportTypes
     */
    public function test_every_analytical_export_type_downloads_a_readable_workbook(string $type): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->get('/api/admin/excel/exports/'.$type);
        $response->assertOk();

        $spreadsheet = $this->spreadsheetFromResponseContent($response->streamedContent());
        $this->assertGreaterThanOrEqual(1, $spreadsheet->getSheetCount());
    }

    /**
     * The module spec's own headline distinction: a student with TWO
     * applications must count once in "distinct students" and twice in
     * "total applications" on the Application Summary export.
     */
    public function test_application_summary_distinguishes_students_from_applications(): void
    {
        $studentUser = $this->makeStudentUser(['roll_number' => 'CC28001']);
        $jobOne = $this->makeJobPosting(['title' => 'Backend Engineer']);
        $jobTwo = $this->makeJobPosting(['title' => 'Frontend Engineer']);

        $this->applyAndAdvance($this->admin, $studentUser, $jobOne, []);
        $this->applyAndAdvance($this->admin, $studentUser, $jobTwo, []);

        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->get('/api/admin/excel/exports/APPLICATION_SUMMARY')->assertOk();

        $spreadsheet = $this->spreadsheetFromResponseContent($response->streamedContent());
        $overview = $spreadsheet->getSheetByName('Overview');
        $rows = $overview->rangeToArray('A1:B10');

        $metrics = [];
        foreach ($rows as $row) {
            if ($row[0] !== null) {
                $metrics[$row[0]] = $row[1];
            }
        }

        // assertEquals, not assertSame: PhpSpreadsheet's own cell
        // read-back may return a numeric value as a float (Excel's
        // internal numeric storage is always double-precision) even
        // though an int was written - the count itself, not its PHP
        // type, is what this test is verifying.
        $this->assertEquals(2, $metrics['Total Applications']);
        $this->assertEquals(1, $metrics['Distinct Students Who Applied']);
    }

    public function test_export_filters_are_validated(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/admin/excel/exports/STUDENTS?department_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }
}
