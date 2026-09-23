<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One (internship assignment, calendar date) attendance mark. Scoped
 * EXCLUSIVELY through internshipAssignment() - there is deliberately
 * no student()/faculty() relation on this model at all: `student_id`/
 * `faculty_id` are never trusted from the client and are always
 * reached by joining through the owning InternshipAssignment (see the
 * migration's docblock and the module spec's "Attendance must NOT
 * create a separate student/internship relationship"). All writes -
 * creation, correction - go exclusively through
 * App\Services\AttendanceService, which is also the only writer of the
 * paired AttendanceHistory rows.
 */
class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendance';

    protected $fillable = [
        'internship_assignment_id',
        'attendance_date',
        'status',
        'check_in_time',
        'check_out_time',
        'remarks',
        'marked_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * attendance_date is a calendar date, never a timestamp. Without
     * this mutator, Eloquent's 'date' cast still writes the raw
     * attribute using the connection's full *datetime* format
     * ("2026-09-23 00:00:00") on save - the per-cast `date:Y-m-d`
     * format suffix only affects array/JSON serialization, not what
     * actually gets persisted. That full-timestamp string sorts
     * *after* a plain "2026-09-23" from/to_date filter value
     * (a longer string that shares a shorter one's prefix always
     * compares greater), which silently broke the admin/faculty
     * date-range filters, and it also failed a direct
     * assertDatabaseHas() check for the bare date. Normalizing the
     * raw attribute to Y-m-d here - a legacy-style mutator, which
     * Eloquent checks before its own cast-based date handling - keeps
     * storage, filtering and assertions all working against the same
     * plain-date representation on every driver (MySQL's real DATE
     * column included).
     */
    public function setAttendanceDateAttribute(DateTimeInterface|string $value): void
    {
        $this->attributes['attendance_date'] = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : Carbon::parse($value)->format('Y-m-d');
    }

    public function internshipAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AttendanceHistory::class);
    }
}
