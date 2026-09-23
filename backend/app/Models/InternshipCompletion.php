<?php

namespace App\Models;

use App\Enums\InternshipCompletionOutcome;
use App\Enums\InternshipCompletionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The single closing record for one internship assignment - the
 * faculty's final COMPLETED/NOT_COMPLETED verdict, optional remarks,
 * and an optional link to the certificate document that evidences it.
 * See the internship_completions migration's docblock and
 * App\Services\InternshipCompletionService (the only writer). No
 * student()/faculty() relation of its own, deliberately mirroring
 * App\Models\Attendance / App\Models\WeeklyProgressReport /
 * App\Models\InternshipDocument - ownership is always derived by
 * walking internshipAssignment -> student_id/faculty_id, never a
 * direct column on this model.
 */
class InternshipCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'internship_assignment_id',
        'completion_date',
        'outcome',
        'final_remarks',
        'completion_certificate_document_id',
        'completed_by',
        'status',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'completion_date' => 'date',
            'outcome' => InternshipCompletionOutcome::class,
            'status' => InternshipCompletionStatus::class,
            'finalized_at' => 'datetime',
        ];
    }

    public function internshipAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class);
    }

    public function completionCertificateDocument(): BelongsTo
    {
        return $this->belongsTo(InternshipDocument::class, 'completion_certificate_document_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(InternshipCompletionStatusHistory::class);
    }
}
