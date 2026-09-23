<?php

namespace App\Models;

use App\Enums\InternshipCompletionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row per internship completion RECORD-status change
 * (DRAFT -> FINALIZED) - see the internship_completion_status_history
 * migration's docblock and App\Services\InternshipCompletionService
 * (the only writer). Mirrors InternshipDocumentStatusHistory exactly,
 * down to UPDATED_AT = null.
 */
class InternshipCompletionStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'internship_completion_status_history';

    protected $fillable = [
        'internship_completion_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => InternshipCompletionStatus::class,
            'to_status' => InternshipCompletionStatus::class,
        ];
    }

    public function internshipCompletion(): BelongsTo
    {
        return $this->belongsTo(InternshipCompletion::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
