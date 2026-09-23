<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
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
