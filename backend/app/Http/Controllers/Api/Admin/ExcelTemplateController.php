<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ExcelImportType;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ExcelImportBatch;
use App\Services\ExcelTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/admin/excel/templates/{type} - module spec section 18's 8
 * downloadable blank templates. Streamed straight to the response
 * (response()->streamDownload()) rather than written to disk first -
 * a template is generated fresh on every request and never persisted,
 * so there is nothing to clean up and no storage/IDOR surface at all
 * for this endpoint.
 */
class ExcelTemplateController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly ExcelTemplateService $templateService) {}

    public function show(string $type): StreamedResponse|JsonResponse
    {
        Gate::authorize('template', ExcelImportBatch::class);

        $importType = ExcelImportType::tryFrom(strtoupper($type));

        if ($importType === null) {
            return $this->failure('Unknown import type.', [], 404);
        }

        $spreadsheet = $this->templateService->build($importType);
        $filename = strtolower($importType->value).'_template.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
