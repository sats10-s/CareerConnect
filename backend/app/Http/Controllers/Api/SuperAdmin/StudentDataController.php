<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Enums\ExcelImportType;
use App\Exceptions\ExcelImportException;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreStudentImportRequest;
use App\Http\Resources\StudentImportBatchResource;
use App\Http\Resources\StudentImportRowResource;
use App\Models\ExcelImportBatch;
use App\Services\StudentImport\StudentImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Super Admin > Student Data: Import, Results update and History
 * (route-gated by role:SUPER_ADMIN). All the work happens in
 * StudentImportService; this only maps HTTP to it.
 */
class StudentDataController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly StudentImportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(['students', 'academic_update'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $types = match ($filters['type'] ?? null) {
            'students' => [ExcelImportType::STUDENTS->value],
            'academic_update' => [ExcelImportType::ACADEMIC_UPDATE->value],
            default => [ExcelImportType::STUDENTS->value, ExcelImportType::ACADEMIC_UPDATE->value],
        };

        $batches = ExcelImportBatch::query()->with(['uploadedBy', 'confirmedBy', 'rolledBackBy'])
            ->whereIn('import_type', $types)->latest('id')->paginate((int) ($filters['per_page'] ?? 15));

        return $this->success(data: [
            'items' => StudentImportBatchResource::collection($batches->items()),
            'meta' => ['current_page' => $batches->currentPage(), 'per_page' => $batches->perPage(), 'total' => $batches->total(), 'last_page' => $batches->lastPage()],
        ]);
    }

    public function store(StoreStudentImportRequest $request): JsonResponse
    {
        try {
            $batch = $this->service->upload($request->file('file'), $request->importType(), $request->user(), $request->validated('semester_label'));
        } catch (ExcelImportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        }

        return $this->success(data: new StudentImportBatchResource($batch->load('uploadedBy')), message: 'File checked. Review the preview before confirming.', status: 201);
    }

    public function show(ExcelImportBatch $batch): JsonResponse
    {
        $this->ensureStudentData($batch);

        return $this->success(data: new StudentImportBatchResource($batch->load(['uploadedBy', 'confirmedBy', 'rolledBackBy'])));
    }

    /** Preview rows for one tab: new | updated | unchanged | warnings | errors. */
    public function rows(Request $request, ExcelImportBatch $batch): JsonResponse
    {
        $this->ensureStudentData($batch);
        $filters = $request->validate([
            'tab' => ['sometimes', Rule::in(['new', 'updated', 'unchanged', 'warnings', 'errors', 'flagged'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:60'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ]);

        $query = $batch->rows()->orderBy('row_number');

        match ($filters['tab'] ?? 'new') {
            'warnings' => $query->where('outcome', 'WARNING'),
            'errors' => $query->where('category', 'error'),
            'flagged' => $query->where('category', 'updated')->where('outcome', 'WARNING'),
            default => $query->where('category', $filters['tab'] ?? 'new'),
        };

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where('raw_row', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
        }

        $rows = $query->paginate((int) ($filters['per_page'] ?? 50));

        return $this->success(data: [
            'items' => StudentImportRowResource::collection($rows->items()),
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function recheck(ExcelImportBatch $batch): JsonResponse
    {
        $this->ensureStudentData($batch);

        return $this->run(fn () => new StudentImportBatchResource($this->service->recheck($batch)->load('uploadedBy')), 'Re-checked against current departments and students.');
    }

    public function confirm(Request $request, ExcelImportBatch $batch): JsonResponse
    {
        $this->ensureStudentData($batch);

        return $this->run(fn () => new StudentImportBatchResource($this->service->confirm($batch, $request->user())->fresh()->load(['uploadedBy', 'confirmedBy'])), 'Import started.');
    }

    public function cancel(Request $request, ExcelImportBatch $batch): JsonResponse
    {
        $this->ensureStudentData($batch);

        return $this->run(fn () => new StudentImportBatchResource($this->service->cancel($batch, $request->user())->load('uploadedBy')), 'Import cancelled. Nothing was changed.');
    }

    public function rollback(Request $request, ExcelImportBatch $batch): JsonResponse
    {
        $this->ensureStudentData($batch);

        return $this->run(fn () => [
            'result' => $this->service->rollback($batch, $request->user()),
            'batch' => new StudentImportBatchResource($batch->fresh()->load(['uploadedBy', 'confirmedBy', 'rolledBackBy'])),
        ], 'Import rolled back.');
    }

    /** One-time passwords for students without a valid mobile - shown once, then gone. */
    public function oneTimePasswords(ExcelImportBatch $batch): JsonResponse
    {
        $this->ensureStudentData($batch);

        return $this->success(data: ['items' => $this->service->takeOneTimePasswords($batch)]);
    }

    public function issues(ExcelImportBatch $batch): StreamedResponse
    {
        $this->ensureStudentData($batch);
        $spreadsheet = $this->service->issuesSpreadsheet($batch);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, "student-import-{$batch->id}-issues.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function ensureStudentData(ExcelImportBatch $batch): void
    {
        abort_unless($batch->import_type->isStudentData(), 404);
    }

    private function run(callable $action, string $message): JsonResponse
    {
        try {
            return $this->success(data: $action(), message: $message);
        } catch (ExcelImportException $e) {
            return $this->failure($e->getMessage(), $e->reasons(), $e->status());
        } catch (Throwable $e) {
            report($e);

            return $this->failure('Something went wrong while running this import. Check the import history for details.', [], 500);
        }
    }
}
