<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ExcelImportType;
use App\Exceptions\ExcelImportException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmExcelImportRequest;
use App\Http\Requests\Admin\StoreExcelImportRequest;
use App\Http\Resources\ExcelImportBatchResource;
use App\Http\Resources\ExcelImportRowResource;
use App\Models\ExcelImportBatch;
use App\Services\ExcelImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Excel Sync Center's Import + History + Preview + Confirm +
 * Rollback endpoints (module spec sections 3/9/10/13). Route-gated by
 * auth:sanctum + role:PLACEMENT_ADMIN,SUPER_ADMIN (see routes/api.php);
 * App\Policies\ExcelImportBatchPolicy adds the object-level layer on
 * top of that route gate, same pattern as every other Admin controller
 * in this codebase. Every write goes through
 * App\Services\ExcelImportService - this controller never touches
 * `excel_import_batches`/`excel_import_rows`/`excel_import_changes`
 * directly, matching the "thin controller" requirement in the module
 * spec's own architecture section.
 */
class ExcelImportController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly ExcelImportService $service) {}

    /**
     * GET /api/admin/excel/imports - the "History" screen. Filterable
     * by import_type/status; newest first.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ExcelImportBatch::class);

        // Student Data imports (Super Admin only) have their own screens.
        $query = ExcelImportBatch::query()->with(['uploadedBy', 'confirmedBy', 'rolledBackBy'])
            ->whereIn('import_type', ExcelImportType::syncCenterValues());

        if ($type = $request->input('import_type')) {
            $query->where('import_type', $type);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $batches = $query->latest()->paginate($perPage);

        return $this->success(
            data: [
                'items' => ExcelImportBatchResource::collection($batches->items()),
                'meta' => [
                    'current_page' => $batches->currentPage(),
                    'per_page' => $batches->perPage(),
                    'total' => $batches->total(),
                    'last_page' => $batches->lastPage(),
                ],
            ],
            message: 'Import batches retrieved successfully.',
        );
    }

    /**
     * POST /api/admin/excel/import - module spec sections 1/8: upload,
     * parse, normalize, match, and validate a file in one request,
     * WITHOUT writing anything to the target tables yet (only to the
     * Excel Sync Center's own excel_import_batches/excel_import_rows
     * bookkeeping tables) - MySQL business data is only ever touched
     * by confirm() below.
     */
    public function store(StoreExcelImportRequest $request): JsonResponse
    {
        Gate::authorize('create', ExcelImportBatch::class);

        try {
            $batch = $this->service->upload($request->file('file'), $request->importType(), $request->user());

            return $this->success(
                data: new ExcelImportBatchResource($batch),
                message: 'File processed. Review the preview before confirming.',
                status: 201,
            );
        } catch (ExcelImportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }
    }

    public function show(ExcelImportBatch $batch): JsonResponse
    {
        abort_if($batch->import_type->isStudentData(), 404);
        Gate::authorize('view', $batch);

        $batch->load(['uploadedBy', 'confirmedBy', 'rolledBackBy']);

        return $this->success(data: new ExcelImportBatchResource($batch), message: 'Import batch retrieved successfully.');
    }

    /**
     * GET /api/admin/excel/imports/{batch}/preview - module spec
     * section 9: every parsed row with its outcome (VALID/WARNING/
     * ERROR), messages, and before/after changes, so the admin can
     * decide whether to confirm before anything touches MySQL.
     * Filterable by `outcome` so a large batch's ERROR rows can be
     * reviewed on their own.
     */
    public function preview(Request $request, ExcelImportBatch $batch): JsonResponse
    {
        abort_if($batch->import_type->isStudentData(), 404);
        Gate::authorize('view', $batch);

        $query = $batch->rows();

        if ($outcome = $request->input('outcome')) {
            $query->where('outcome', $outcome);
        }

        $perPage = min(max((int) $request->input('per_page', 50), 1), 500);
        $rows = $query->orderBy('row_number')->paginate($perPage);

        return $this->success(
            data: [
                'batch' => new ExcelImportBatchResource($batch),
                'items' => ExcelImportRowResource::collection($rows->items()),
                'meta' => [
                    'current_page' => $rows->currentPage(),
                    'per_page' => $rows->perPage(),
                    'total' => $rows->total(),
                    'last_page' => $rows->lastPage(),
                ],
            ],
            message: 'Preview retrieved successfully.',
        );
    }

    /**
     * POST /api/admin/excel/imports/{batch}/confirm - module spec
     * section 10: the ONLY endpoint that ever writes Excel-sourced
     * data into applications/interviews/internship_assignments/
     * ppo_records/attendance/students, and always through the
     * pre-existing domain services (see
     * App\Services\ExcelImportService's own docblock).
     */
    public function confirm(ConfirmExcelImportRequest $request, ExcelImportBatch $batch): JsonResponse
    {
        abort_if($batch->import_type->isStudentData(), 404);
        Gate::authorize('confirm', $batch);

        try {
            $result = $this->service->confirm($batch, $request->user(), $request->validated('row_ids'));

            return $this->success(data: ['batch' => new ExcelImportBatchResource($batch->fresh()), 'result' => $result], message: 'Import confirmed.');
        } catch (ExcelImportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }
    }

    /**
     * POST /api/admin/excel/imports/{batch}/rollback - module spec
     * section 13. See App\Services\ExcelImportService::rollback()'s
     * own docblock for the REVERTED/CONFLICT/NOT_ROLLBACKABLE
     * three-outcome result this returns.
     */
    public function rollback(Request $request, ExcelImportBatch $batch): JsonResponse
    {
        abort_if($batch->import_type->isStudentData(), 404);
        Gate::authorize('rollback', $batch);

        try {
            $result = $this->service->rollback($batch, $request->user());

            return $this->success(data: ['batch' => new ExcelImportBatchResource($batch->fresh()), 'result' => $result], message: 'Rollback completed.');
        } catch (ExcelImportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }
    }
}
