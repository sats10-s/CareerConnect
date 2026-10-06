<?php

namespace App\Models;

use App\Enums\CompanyMemberRole;
use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A recruiter organisation. Created either by the placement office
 * (source ADMIN, approved immediately) or through public company
 * self-registration (source SELF_REGISTERED, PENDING until a Super Admin
 * approves it - see App\Services\CompanyRegistrationService).
 *
 * `status`, `source`, `reviewed_*` are deliberately not fillable: they only
 * ever change through the registration/review service.
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'industry',
        'website',
        'logo_path',
        'description',
        'is_active',
        'headquarters',
        'company_size',
        'registration_number',
        'contact_name',
        'contact_email',
        'contact_phone',
    ];

    /** Mirror the column defaults so a freshly created admin company serialises its status. */
    protected $attributes = [
        'status' => 'APPROVED',
        'source' => 'ADMIN',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => CompanyStatus::class,
            'source' => CompanySource::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    public function internships(): HasMany
    {
        return $this->hasMany(Internship::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('company_member_role', CompanyMemberRole::OWNER->value);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApproved(): bool
    {
        return $this->status === CompanyStatus::APPROVED;
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CompanyStatus::APPROVED->value);
    }
}
