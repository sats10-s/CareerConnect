<?php

namespace App\Models;

use App\Enums\PpoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One optional Pre-Placement Offer (PPO) record per internship
 * assignment - see the ppo_records migration's docblock and
 * App\Services\InternshipCompletionService (the only writer). No
 * student()/faculty() relation of its own, mirroring
 * App\Models\InternshipCompletion exactly - ownership is always
 * derived by walking internshipAssignment -> student_id/faculty_id.
 * `company_id` IS its own direct column (unlike student/faculty) -
 * see the migration's docblock for why.
 */
class PpoRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'internship_assignment_id',
        'company_id',
        'offered_at',
        'joining_date',
        'designation',
        'package',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'date',
            'joining_date' => 'date',
            'package' => 'decimal:2',
            'status' => PpoStatus::class,
        ];
    }

    public function internshipAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PpoRecordStatusHistory::class);
    }
}
