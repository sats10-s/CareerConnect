<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'roll_number',
        'department_id',
        'batch_id',
        'phone',
        'faculty_mentor_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function facultyMentor(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'faculty_mentor_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
