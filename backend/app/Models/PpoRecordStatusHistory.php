<?php

namespace App\Models;

use App\Enums\PpoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row per PPO record status change (null -> OFFERED,
 * OFFERED -> ACCEPTED, OFFERED -> DECLINED) - see the
 * ppo_record_status_history migration's docblock and
 * App\Services\InternshipCompletionService (the only writer). Mirrors
 * InternshipCompletionStatusHistory/InternshipDocumentStatusHistory
 * exactly, down to UPDATED_AT = null.
 */
class PpoRecordStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ppo_record_status_history';

    protected $fillable = [
        'ppo_record_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => PpoStatus::class,
            'to_status' => PpoStatus::class,
        ];
    }

    public function ppoRecord(): BelongsTo
    {
        return $this->belongsTo(PpoRecord::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
