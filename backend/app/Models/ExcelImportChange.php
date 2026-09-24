<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field-level change a CONFIRMED (or rolled-back) Excel import
 * actually made - see the excel_import_changes migration's docblock
 * for the field list/reasoning and App\Services\ExcelImportService for
 * the only writer. Append-only, like every other audit table in this
 * codebase - see UPDATED_AT = null below.
 */
class ExcelImportChange extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'import_batch_id',
        'excel_import_row_id',
        'entity_type',
        'entity_id',
        'field',
        'old_value',
        'new_value',
        'changed_by',
        'is_creation',
        'is_rollback_of',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'is_creation' => 'boolean',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExcelImportBatch::class, 'import_batch_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(ExcelImportRow::class, 'excel_import_row_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'is_rollback_of');
    }
}
