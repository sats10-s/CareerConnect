<?php

namespace App\Models;

use App\Enums\InterviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'interview_status_history';

    protected $fillable = [
        'interview_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => InterviewStatus::class,
            'to_status' => InterviewStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
