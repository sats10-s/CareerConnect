<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ExcelExportType;
use App\Exceptions\ExcelImportException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExcelExportFilterRequest;
use App\Models\ExcelImportBatch;
use App\Services\ExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/admin/excel/exports/{type} - module spec sections 19-31's
 * raw-data AND analytical/summary exports, both served through this
 * one endpoint (App\Enums\ExcelExportType::isRaw()/isAnalytical()
 * dispatch inside App\Services\ExcelExportService, never a second
 * duplicated export controller). Streamed straight to the response,
 * same reasoning as ExcelTemplateController - an export reflects live
 * MySQL state at request time and is never persisted server-side.
 */
class ExcelExportController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly ExcelExportService $exportService) {}

    public function show(ExcelExportFilterRequest $request, string $type): StreamedResponse|JsonResponse
    {
        Gate::authorize('export', ExcelImportBatch::class);

        $exportType = ExcelExportType::tryFrom(strtoupper($type));

        if ($exportType === null) {
            return $this->failure('Unknown export type.', [], 404);
        }

        try {
            $spreadsheet = $this->exportService->build($exportType, $request->filters());
        } catch (ExcelImportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        $filename = strtolower($exportType->value).'_'.now()->format('Y_m_d_His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
