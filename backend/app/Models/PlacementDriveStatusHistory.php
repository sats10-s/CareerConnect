<?php

namespace App\Models;

use App\Enums\PlacementDriveStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a placement drive's status audit trail - mirrors
 * App\Models\ApplicationStatusHistory / App\Models\InterviewStatusHistory
 * exactly. App\Services\PlacementDriveService is the only writer.
 */
class PlacementDriveStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'placement_drive_status_history';

    protected $fillable = [
        'placement_drive_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => PlacementDriveStatus::class,
            'to_status' => PlacementDriveStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function placementDrive(): BelongsTo
    {
        return $this->belongsTo(PlacementDrive::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
