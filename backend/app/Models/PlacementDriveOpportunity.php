<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One JobPosting/Internship attached to one PlacementDrive - a pure
 * grouping record (see the `placement_drive_opportunities` migration's
 * docblock). Never duplicates job/internship data, and never touches
 * the attached opportunity's own `status`/`is_active` - see
 * App\Services\PlacementDriveService::attachOpportunity().
 */
class PlacementDriveOpportunity extends Model
{
    use HasFactory;

    protected $fillable = [
        'placement_drive_id',
        'opportunity_type',
        'opportunity_id',
    ];

    public function placementDrive(): BelongsTo
    {
        return $this->belongsTo(PlacementDrive::class);
    }

    /**
     * Resolves to the attached App\Models\JobPosting or
     * App\Models\Internship - `opportunity_type` stores the full model
     * class name, exactly like Application::applicable() does (no
     * morph map is registered anywhere in this app).
     */
    public function opportunity(): MorphTo
    {
        return $this->morphTo();
    }
}
