<?php

namespace App\Models;

use App\Enums\ExcelImportBatchStatus;
use App\Enums\ExcelImportType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded Excel file's full lifecycle - see the
 * excel_import_batches migration's docblock for the field list and
 * App\Services\ExcelImportService for the only writer of this model.
 */
class ExcelImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_type',
        'uploaded_by',
        'original_filename',
        'stored_filename',
        'storage_path',
        'status',
        'total_rows',
        'matched_rows',
        'valid_rows',
        'warning_rows',
        'error_rows',
        'confirmed_by',
        'confirmed_at',
        'rolled_back_by',
        'rolled_back_at',
        'error_message',
        'summary',
        'progress_total',
        'progress_done',
        'secret',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'import_type' => ExcelImportType::class,
            'status' => ExcelImportBatchStatus::class,
            'total_rows' => 'integer',
            'matched_rows' => 'integer',
            'valid_rows' => 'integer',
            'warning_rows' => 'integer',
            'error_rows' => 'integer',
            'confirmed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'summary' => 'array',
            'progress_total' => 'integer',
            'progress_done' => 'integer',
            'secret' => 'encrypted:array',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rolledBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ExcelImportRow::class, 'import_batch_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ExcelImportChange::class, 'import_batch_id');
    }
}
