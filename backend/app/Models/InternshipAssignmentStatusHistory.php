<?php

namespace App\Models;

use App\Enums\InternshipAssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row per internship assignment STATUS change - see
 * the internship_assignment_status_history migration's docblock and
 * App\Services\InternshipAssignmentService (the only writer). Mirrors
 * InterviewStatusHistory/PlacementDriveStatusHistory exactly, down to
 * UPDATED_AT = null.
 */
class InternshipAssignmentStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'internship_assignment_status_history';

    protected $fillable = [
        'internship_assignment_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => InternshipAssignmentStatus::class,
            'to_status' => InternshipAssignmentStatus::class,
        ];
    }

    public function internshipAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
