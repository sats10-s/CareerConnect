<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a Student Data import preview: where it came from (sheet +
 * row), the cleaned values, old -> new changes, and every problem found.
 *
 * @mixin \App\Models\ExcelImportRow
 */
class StudentImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = $this->confirm_context['values'] ?? [];

        return [
            'id' => $this->id,
            'sheet' => $this->raw_row['sheet'] ?? null,
            'sheet_row' => $this->raw_row['sheet_row'] ?? null,
            'category' => $this->category,
            'outcome' => $this->outcome?->value,
            'roll_number' => ($values['roll_number'] ?? '') !== '' ? $values['roll_number'] : (string) ($this->raw_row['data']['roll_number'] ?? ''),
            'name' => $values['name'] ?? ($this->raw_row['data']['name'] ?? null),
            'values' => $values,
            'flagged' => (bool) ($this->confirm_context['flag'] ?? false),
            'unknown_department' => $this->confirm_context['unknown_department'] ?? null,
            'needs_one_time_password' => (bool) ($this->confirm_context['needs_one_time_password'] ?? false),
            'messages' => $this->messages,
            'changes' => $this->changes,
            'applied' => $this->applied,
            'apply_error' => $this->apply_error,
        ];
    }
}
