<?php

namespace App\Models;

use App\Enums\WeeklyReportStatus;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One (internship assignment, week number) weekly progress report.
 * Scoped EXCLUSIVELY through internshipAssignment() - there is
 * deliberately no student()/faculty() relation on this model at all:
 * `student_id`/`faculty_id` are never trusted from the client and are
 * always reached by joining through the owning InternshipAssignment
 * (see the migration's docblock, mirroring App\Models\Attendance's own
 * "no separate student/internship relationship" rule). All writes -
 * draft creation, draft update, submit, review - go exclusively
 * through App\Services\WeeklyProgressReportService, which is also the
 * only writer of the paired WeeklyProgressReportStatusHistory rows.
 */
class WeeklyProgressReport extends Model
{
    use HasFactory;

    protected $table = 'weekly_progress_reports';

    protected $fillable = [
        'internship_assignment_id',
        'week_number',
        'week_start_date',
        'week_end_date',
        'work_done',
        'learning_outcomes',
        'challenges',
        'next_week_plan',
        'status',
        'student_submitted_at',
        'faculty_reviewed_at',
        'faculty_review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'week_number' => 'integer',
            'week_start_date' => 'date',
            'week_end_date' => 'date',
            'status' => WeeklyReportStatus::class,
            'student_submitted_at' => 'datetime',
            'faculty_reviewed_at' => 'datetime',
        ];
    }

    /**
     * week_start_date is a calendar date, never a timestamp. Without
     * this mutator, Eloquent's 'date' cast still writes the raw
     * attribute using the connection's full *datetime* format
     * ("2026-09-23 00:00:00") on save - see
     * App\Models\Attendance::setAttendanceDateAttribute()'s docblock
     * for the full explanation of why (this is the exact same bug,
     * fixed proactively here from the start rather than after a failing
     * test). Normalizing the raw attribute to Y-m-d here - a
     * legacy-style mutator, which Eloquent checks before its own
     * cast-based date handling - keeps storage, range filtering, and
     * the "week_end_date = week_start_date + 6 days" check all working
     * against the same plain-date representation on every driver.
     */
    public function setWeekStartDateAttribute(DateTimeInterface|string $value): void
    {
        $this->attributes['week_start_date'] = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : Carbon::parse($value)->format('Y-m-d');
    }

    public function setWeekEndDateAttribute(DateTimeInterface|string $value): void
    {
        $this->attributes['week_end_date'] = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : Carbon::parse($value)->format('Y-m-d');
    }

    public function internshipAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(WeeklyProgressReportStatusHistory::class);
    }
}
