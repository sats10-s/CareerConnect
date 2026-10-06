<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
use App\Enums\WorkMode;
use App\Models\Concerns\HasEligibilityCriteria;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Backed by the `internships` table. The core placeholder shape
 * (company/title/description/location/duration_months/stipend/
 * posted_by/is_active) predates this module and is untouched; the
 * Jobs & Internships Backend Foundation increment adds the
 * publish-workflow and eligibility-criteria columns/relations below
 * (see 2024_01_02_000002_add_opportunity_fields_to_internships_table).
 */
class Internship extends Model
{
    use HasEligibilityCriteria;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'location',
        'duration_months',
        'stipend',
        'posted_by',
        'updated_by',
        'is_active',
        'status',
        'work_mode',
        'application_deadline',
        'openings',
        'source',
        'submitted_for_review_at',
    ];

    /** Mirrors the column default (ADMIN = posted by the placement office). */
    protected $attributes = ['source' => 'ADMIN'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => OpportunityStatus::class,
            'work_mode' => WorkMode::class,
            'application_deadline' => 'date',
            'openings' => 'integer',
            'submitted_for_review_at' => 'datetime',
            'duration_months' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Semantic alias for postedBy() - `posted_by` is reused as the
     * "creator" column for this module (see JobPosting's identical
     * decision).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function applications(): MorphMany
    {
        return $this->morphMany(Application::class, 'applicable');
    }
}
