<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OpportunityEligibleYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
    ];

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }

    public function opportunity(): MorphTo
    {
        return $this->morphTo();
    }
}
