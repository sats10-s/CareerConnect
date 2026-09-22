<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Placeholder model - full company-portal functionality is out of
 * scope for this foundation and will be built in a later prompt.
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
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    public function internships(): HasMany
    {
        return $this->hasMany(Internship::class);
    }
}
