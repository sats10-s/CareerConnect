<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only creation/correction audit trail for one Attendance
 * record - see the attendance_history migration's docblock for the
 * full field-shape rationale. `changed_at` is mapped onto Eloquent's
 * CREATED_AT constant (rather than a plain `created_at` column) so
 * `AttendanceHistory::create([...])` still auto-populates it exactly
 * like every other append-only history model in this codebase, while
 * keeping the module spec's literal field name. UPDATED_AT is
 * disabled - a history row is only ever inserted, never modified.
 */
class AttendanceHistory extends Model
{
    use HasFactory;

    const CREATED_AT = 'changed_at';

    const UPDATED_AT = null;

    protected $table = 'attendance_history';

    protected $fillable = [
        'attendance_id',
        'old_status',
        'new_status',
        'old_check_in_time',
        'new_check_in_time',
        'old_check_out_time',
        'new_check_out_time',
        'old_remarks',
        'new_remarks',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'old_status' => AttendanceStatus::class,
            'new_status' => AttendanceStatus::class,
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
