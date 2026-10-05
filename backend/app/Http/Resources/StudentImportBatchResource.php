<?php

namespace App\Http\Resources;

use App\Services\StudentImport\StudentImportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Student Data import (full student list or results update) for the
 * Import / Results update / History screens. Never exposes the stored
 * file's path or the one-time passwords themselves - only whether any are
 * waiting to be viewed.
 *
 * @mixin \App\Models\ExcelImportBatch
 */
class StudentImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = $this->summary ?? [];
        $blocker = app(StudentImportService::class)->rollbackBlocker($this->resource);

        return [
            'id' => $this->id,
            'type' => $this->import_type === \App\Enums\ExcelImportType::STUDENTS ? 'students' : 'academic_update',
            'type_label' => $this->import_type?->label(),
            'status' => $this->status?->value,
            'original_filename' => $this->original_filename,
            'semester_label' => $summary['semester_label'] ?? null,
            'counts' => $summary['counts'] ?? ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0, 'warning' => 0],
            'total_rows' => $this->total_rows,
            'sheets' => $summary['sheets'] ?? [],
            'unknown_departments' => $summary['unknown_departments'] ?? [],
            'result' => $summary['result'] ?? null,
            'progress' => ['done' => $this->progress_done, 'total' => $this->progress_total],
            'has_one_time_passwords' => ! empty($this->secret),
            'can_rollback' => $blocker === null,
            'rollback_blocker' => $this->status?->value === 'CONFIRMED' ? $blocker : null,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => ['id' => $this->uploadedBy->id, 'name' => $this->uploadedBy->name]),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy ? ['id' => $this->confirmedBy->id, 'name' => $this->confirmedBy->name] : null),
            'rolled_back_by' => $this->whenLoaded('rolledBackBy', fn () => $this->rolledBackBy ? ['id' => $this->rolledBackBy->id, 'name' => $this->rolledBackBy->name] : null),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'rolled_back_at' => $this->rolled_back_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
