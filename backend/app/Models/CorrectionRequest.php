<?php

namespace App\Models;

use App\Enums\CorrectionRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectionRequest extends Model
{
    /** The locked college-record fields a student can report as wrong. */
    public const FIELDS = [
        'name' => 'Name',
        'roll_number' => 'JNTU No',
        'year_of_joining' => 'Year of joining',
        'gender' => 'Gender',
        'department' => 'Department',
        'section' => 'Section',
        'phone' => 'Mobile number',
        'cgpa' => 'CGPA',
        'backlogs' => 'Backlogs',
        'ssc_score' => '10th score',
        'inter_score' => 'Inter / Diploma score',
        'other' => 'Something else',
    ];

    protected $fillable = ['student_id', 'field', 'note', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'status' => CorrectionRequestStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
