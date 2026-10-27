<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OpportunityRequiredSkill extends Model
{
    use HasFactory;

    protected $fillable = [
        'skill_name',
        'sort_order',
    ];

    public function opportunity(): MorphTo
    {
        return $this->morphTo();
    }
}
