<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row per faculty REASSIGNMENT on an internship
 * assignment - a dedicated audit trail, deliberately separate from
 * InternshipAssignmentStatusHistory (a faculty change is not a status
 * transition - see the internship_assignment_faculty_history
 * migration's docblock). Written exclusively by
 * App\Services\InternshipAssignmentService::reassignFaculty().
 */
class InternshipAssignmentFacultyHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'internship_assignment_faculty_history';

    protected $fillable = [
        'internship_assignment_id',
        'from_faculty_id',
        'to_faculty_id',
        'changed_by',
        'remarks',
    ];

    public function internshipAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class);
    }

    public function fromFaculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'from_faculty_id');
    }

    public function toFaculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'to_faculty_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
