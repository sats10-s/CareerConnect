<?php

namespace App\Models;

use App\Enums\InternshipAssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The faculty-coordination relationship for a SELECTED internship
 * application: Student -> Internship -> Application -> SELECTED ->
 * InternshipAssignment -> Faculty Coordinator. application_id/
 * internship_id/student_id are always derived from the selected
 * application at creation time (see
 * App\Services\InternshipAssignmentService::create()), never trusted
 * from the client - see the migration's docblock for why they are
 * still their own FK columns rather than requiring a join through
 * `applications` for every query. All lifecycle writes - creation,
 * activation, completion, cancellation, faculty reassignment - go
 * exclusively through App\Services\InternshipAssignmentService, which
 * is also the only writer of the paired InternshipAssignmentStatusHistory/
 * InternshipAssignmentFacultyHistory rows.
 */
class InternshipAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'internship_id',
        'student_id',
        'faculty_id',
        'assigned_by',
        'assigned_at',
        'status',
        'started_at',
        'ended_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InternshipAssignmentStatus::class,
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(InternshipAssignmentStatusHistory::class);
    }

    public function facultyHistory(): HasMany
    {
        return $this->hasMany(InternshipAssignmentFacultyHistory::class);
    }

    /**
     * Attendance Management - every Attendance row marked against this
     * assignment. This is the ONLY path to an assignment's attendance;
     * see App\Models\Attendance's docblock for why that model has no
     * student()/faculty() relation of its own.
     */
    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Weekly Progress Reports - every WeeklyProgressReport row created
     * against this assignment. This is the ONLY path to an assignment's
     * weekly reports; see App\Models\WeeklyProgressReport's docblock
     * for why that model has no student()/faculty() relation of its
     * own, mirroring attendance() above.
     */
    public function weeklyProgressReports(): HasMany
    {
        return $this->hasMany(WeeklyProgressReport::class);
    }

    /**
     * Internship Document Management - every InternshipDocument row
     * uploaded against this assignment. This is the ONLY path to an
     * assignment's documents; see App\Models\InternshipDocument's
     * docblock for why that model has no student()/faculty() relation
     * of its own, mirroring attendance()/weeklyProgressReports() above.
     */
    public function internshipDocuments(): HasMany
    {
        return $this->hasMany(InternshipDocument::class);
    }

    /**
     * Internship Completion & PPO - the single closing record for this
     * assignment, if one has been recorded yet (see
     * App\Models\InternshipCompletion's docblock and the
     * internship_completions migration's unique-per-assignment index).
     * HasOne rather than HasMany - unlike attendance/weeklyProgressReports/
     * internshipDocuments, there is at most one of these per assignment.
     */
    public function completion(): HasOne
    {
        return $this->hasOne(InternshipCompletion::class);
    }

    /**
     * Internship Completion & PPO - the single optional Pre-Placement
     * Offer record for this assignment, if one has been recorded (see
     * App\Models\PpoRecord's docblock and the ppo_records migration's
     * unique-per-assignment index). A PPO is entirely optional - most
     * assignments will never have one.
     */
    public function ppoRecord(): HasOne
    {
        return $this->hasOne(PpoRecord::class);
    }
}
