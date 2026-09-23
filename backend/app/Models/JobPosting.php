<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\OpportunityStatus;
use App\Enums\WorkMode;
use App\Models\Concerns\HasEligibilityCriteria;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Backed by the `job_postings` table. The core placeholder shape
 * (company/title/description/location/salary_range/posted_by/
 * is_active) predates this module and is untouched; the Jobs &
 * Internships Backend Foundation increment adds the publish-workflow
 * and eligibility-criteria columns/relations below (see
 * 2024_01_02_000001_add_opportunity_fields_to_job_postings_table).
 */
class JobPosting extends Model
{
    use HasEligibilityCriteria;
    use HasFactory;

    protected $table = 'job_postings';

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'location',
        'salary_range',
        'posted_by',
        'updated_by',
        'is_active',
        'status',
        'employment_type',
        'work_mode',
        'application_deadline',
        'openings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => OpportunityStatus::class,
            'employment_type' => EmploymentType::class,
            'work_mode' => WorkMode::class,
            'application_deadline' => 'date',
            'openings' => 'integer',
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
     * "creator" column for this module rather than adding a
     * differently-named duplicate column (see the migration's
     * "Schema decisions" note).
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
