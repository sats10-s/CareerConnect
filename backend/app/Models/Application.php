<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A student's application to a JobPosting or Internship (`applicable`,
 * a polymorphic relation - see 2024_01_01_000015_create_applications_table
 * for why it's `applicable_type`/`applicable_id` rather than the
 * `opportunity_type`/`opportunity_id` naming the eligibility tables
 * use). All lifecycle writes - creation, withdrawal, admin status
 * changes - go exclusively through App\Services\ApplicationService,
 * which is also the only writer of the paired
 * ApplicationStatusHistory row for every change; nothing else should
 * call `$application->update(['status' => ...])` directly.
 */
class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'applicable_type',
        'applicable_id',
        'status',
        'applied_at',
        'withdrawn_at',
        'selected_at',
        'rejection_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'applied_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'selected_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function applicable(): MorphTo
    {
        return $this->morphTo();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class);
    }
}
