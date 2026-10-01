<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super Admin > Batches. Delete is only allowed while no student is registered under the batch. */
class BatchController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $batches = Batch::query()->withCount('students')->orderByDesc('start_year')->get();

        return $this->success(data: ['items' => $batches->map(fn (Batch $b) => $this->present($b))]);
    }

    public function store(Request $request): JsonResponse
    {
        $batch = Batch::create($this->validated($request));
        $this->audit->record('batch.created', "Created batch {$batch->name}", $batch);

        return $this->success(data: $this->present($batch->loadCount('students')), message: 'Batch added.', status: 201);
    }

    public function update(Request $request, Batch $batch): JsonResponse
    {
        $batch->fill($this->validated($request, $batch));
        $changed = array_keys($batch->getDirty());
        $batch->save();
        if ($changed) {
            $this->audit->record('batch.updated', "Updated batch {$batch->name}", $batch, ['fields' => $changed]);
        }

        return $this->success(data: $this->present($batch->loadCount('students')), message: 'Batch updated.');
    }

    public function destroy(Batch $batch): JsonResponse
    {
        $batch->loadCount('students');
        if ($batch->students_count > 0) {
            return $this->failure("{$batch->name} still has {$batch->students_count} student(s), so it can't be deleted.", [], 409);
        }

        $name = $batch->name;
        $batch->delete();
        $this->audit->record('batch.deleted', "Deleted batch {$name}", null, ['id' => $batch->id, 'name' => $name]);

        return $this->success(message: 'Batch deleted.');
    }

    private function validated(Request $request, ?Batch $batch = null): array
    {
        $start = $request->input('start_year', $batch?->start_year);
        $end = $request->input('end_year', $batch?->end_year);

        $data = $request->validate([
            'start_year' => [$batch ? 'sometimes' : 'required', 'integer', 'between:2000,2100'],
            'end_year' => [$batch ? 'sometimes' : 'required', 'integer', 'between:2000,2100', 'gt:start_year'],
            'name' => ['sometimes', 'nullable', 'string', 'max:32'],
        ], [
            'end_year.gt' => 'The end year must be after the start year.',
        ]);

        if (is_numeric($start) && is_numeric($end)) {
            if ((int) $end <= (int) $start) {
                abort(response()->json(['message' => 'The end year must be after the start year.', 'errors' => ['end_year' => ['The end year must be after the start year.']]], 422));
            }
            $clash = Batch::where('start_year', $start)->where('end_year', $end)->when($batch, fn ($q) => $q->whereKeyNot($batch->id))->exists();
            if ($clash) {
                abort(response()->json(['message' => 'This batch already exists.', 'errors' => ['start_year' => ["A {$start}-{$end} batch already exists."]]], 422));
            }
        }

        // The name defaults to "start-end", the format used everywhere else.
        if (empty($data['name']) && (isset($data['start_year']) || isset($data['end_year']))) {
            $data['name'] = "{$start}-{$end}";
        } elseif (array_key_exists('name', $data) && empty($data['name'])) {
            unset($data['name']);
        }

        return $data;
    }

    private function present(Batch $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'start_year' => $b->start_year,
            'end_year' => $b->end_year,
            'students_count' => (int) ($b->students_count ?? 0),
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
