<?php

namespace App\Models;

use App\Enums\DriveType;
use App\Enums\PlacementDriveStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A college recruitment EVENT/container: Company -> Placement Drive ->
 * Job/Internship opportunity -> Applications -> Interviews ->
 * Selections. A drive never owns applications or interviews itself -
 * it only groups existing JobPosting/Internship rows via
 * `opportunities()` (see App\Models\PlacementDriveOpportunity).
 * Application/interview/selection counts are always aggregated by
 * walking that join table out to the real `applications`/`interviews`
 * tables (see App\Http\Controllers\Concerns\ComputesPlacementDriveStats),
 * never stored or duplicated here.
 *
 * All lifecycle writes - creation, publish, close, cancel, plain field
 * edits, attach/detach - go exclusively through
 * App\Services\PlacementDriveService, which is also the only writer of
 * the paired PlacementDriveStatusHistory row for every status change;
 * nothing else should call `$placementDrive->update(['status' => ...])`
 * directly.
 */
class PlacementDrive extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'drive_type',
        'venue',
        'scheduled_at',
        'application_deadline',
        'status',
        'created_by',
        'updated_by',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'drive_type' => DriveType::class,
            'status' => PlacementDriveStatus::class,
            'scheduled_at' => 'datetime',
            'application_deadline' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(PlacementDriveOpportunity::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PlacementDriveStatusHistory::class);
    }
}
