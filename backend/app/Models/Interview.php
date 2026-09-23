<?php

namespace App\Models;

use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One interview round, always belonging to exactly one Application -
 * there is no standalone/student-only interview. All lifecycle writes
 * (creation, reschedule, complete, cancel, plain field edits) go
 * exclusively through App\Services\InterviewService, which is also
 * the only writer of the paired InterviewStatusHistory row for every
 * status change; nothing else should call
 * $interview->update(['status' => ...]) directly.
 */
class Interview extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'round',
        'title',
        'scheduled_at',
        'duration_minutes',
        'mode',
        'meeting_url',
        'venue',
        'interviewer_name',
        'interviewer_email',
        'panel_notes',
        'status',
        'created_by',
        'updated_by',
        'cancelled_at',
        'cancellation_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'mode' => InterviewMode::class,
            'status' => InterviewStatus::class,
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(InterviewStatusHistory::class);
    }
}
