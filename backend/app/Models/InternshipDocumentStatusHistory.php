<?php

namespace App\Models;

use App\Enums\InternshipDocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row per internship document STATUS change - see the
 * internship_document_status_history migration's docblock and
 * App\Services\InternshipDocumentService (the only writer). Mirrors
 * WeeklyProgressReportStatusHistory exactly, down to UPDATED_AT = null.
 */
class InternshipDocumentStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'internship_document_status_history';

    protected $fillable = [
        'internship_document_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => InternshipDocumentStatus::class,
            'to_status' => InternshipDocumentStatus::class,
        ];
    }

    public function internshipDocument(): BelongsTo
    {
        return $this->belongsTo(InternshipDocument::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
