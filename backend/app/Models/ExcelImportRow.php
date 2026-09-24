<?php

namespace App\Models;

use App\Enums\ExcelRowOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parsed/matched/validated Excel row within a batch - see the
 * excel_import_rows migration's docblock for why this table exists and
 * App\Services\ExcelValidationService for the only writer of a new
 * row. `entity_type`/`entity_id` are plain columns, not a real
 * Eloquent MorphTo - see that migration's docblock.
 *
 * `changes` shape: list<array{field: string, old_value: mixed, new_value: mixed, label?: string}>
 * `messages` shape: list<string>
 * `confirm_context` shape: varies per App\Enums\ExcelImportType - see
 * App\Services\ExcelImportService::confirmRow() for what each import
 * type reads back out of it.
 */
class ExcelImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'raw_row',
        'entity_type',
        'entity_id',
        'confirm_context',
        'outcome',
        'messages',
        'changes',
        'applied',
        'applied_at',
        'apply_error',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw_row' => 'array',
            'entity_id' => 'integer',
            'confirm_context' => 'array',
            'outcome' => ExcelRowOutcome::class,
            'messages' => 'array',
            'changes' => 'array',
            'applied' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExcelImportBatch::class, 'import_batch_id');
    }
}
