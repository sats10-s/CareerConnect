<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Placeholder model - the real application workflow (interview
 * scheduling, offer letters, status history, etc.) is out of scope
 * for this foundation and will be built in a later prompt.
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
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'applied_at' => 'datetime',
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
}
