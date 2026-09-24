<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Preview screen's row-level payload (module spec section 9 -
 * "Show before/after values... show counts... never modify MySQL
 * before confirmation"). `changes` is exactly the before/after diff
 * App\Services\ExcelImportService's build*Row() methods computed at
 * upload time; `raw_row` is the parsed Excel data as-is so an admin
 * can cross-check a rejected row against what they actually typed.
 *
 * @mixin \App\Models\ExcelImportRow
 */
class ExcelImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'raw_row' => $this->raw_row,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'outcome' => $this->outcome?->value,
            'messages' => $this->messages,
            'changes' => $this->changes,
            'applied' => $this->applied,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'apply_error' => $this->apply_error,
        ];
    }
}
