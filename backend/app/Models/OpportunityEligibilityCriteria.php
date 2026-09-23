<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OpportunityEligibilityCriteria extends Model
{
    use HasFactory;

    protected $table = 'opportunity_eligibility_criteria';

    protected $fillable = [
        'min_cgpa',
        'max_backlogs',
    ];

    protected function casts(): array
    {
        return [
            'min_cgpa' => 'decimal:2',
            'max_backlogs' => 'integer',
        ];
    }

    public function opportunity(): MorphTo
    {
        return $this->morphTo();
    }
}
