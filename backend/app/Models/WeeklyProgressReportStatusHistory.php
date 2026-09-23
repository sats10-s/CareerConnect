<?php

namespace App\Models;

use App\Enums\WeeklyReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row per weekly progress report STATUS change - see
 * the weekly_progress_report_status_history migration's docblock and
 * App\Services\WeeklyProgressReportService (the only writer). Mirrors
 * InternshipAssignmentStatusHistory exactly, down to UPDATED_AT = null.
 */
class WeeklyProgressReportStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'weekly_progress_report_status_history';

    protected $fillable = [
        'weekly_progress_report_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => WeeklyReportStatus::class,
            'to_status' => WeeklyReportStatus::class,
        ];
    }

    public function weeklyProgressReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyProgressReport::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
