<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Excel Sync Center's "History" list/detail payload (module spec
 * section 3's admin menu: Import/Export/Templates/History) - every
 * count/status field an admin needs to decide whether to open the
 * preview, confirm, or roll a batch back, without exposing the
 * server-only `storage_path` (module spec section 33: never leak a
 * private file's on-disk location to the client).
 *
 * @mixin \App\Models\ExcelImportBatch
 */
class ExcelImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'import_type' => $this->import_type?->value,
            'import_type_label' => $this->import_type?->label(),
            'status' => $this->status?->value,
            'original_filename' => $this->original_filename,
            'counts' => [
                'total_rows' => $this->total_rows,
                'matched_rows' => $this->matched_rows,
                'valid_rows' => $this->valid_rows,
                'warning_rows' => $this->warning_rows,
                'error_rows' => $this->error_rows,
            ],
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => ['id' => $this->uploadedBy->id, 'name' => $this->uploadedBy->name]),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy ? ['id' => $this->confirmedBy->id, 'name' => $this->confirmedBy->name] : null),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'rolled_back_by' => $this->whenLoaded('rolledBackBy', fn () => $this->rolledBackBy ? ['id' => $this->rolledBackBy->id, 'name' => $this->rolledBackBy->name] : null),
            'rolled_back_at' => $this->rolled_back_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
