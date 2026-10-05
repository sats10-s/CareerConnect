<?php

namespace App\Models;

use App\Enums\AcademicRecordSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One CGPA/backlogs change - written only through App\Services\Student\AcademicRecordService. */
class StudentAcademicRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'student_id',
        'cgpa',
        'backlogs',
        'previous_cgpa',
        'previous_backlogs',
        'semester_label',
        'source',
        'import_id',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'cgpa' => 'decimal:2',
            'backlogs' => 'integer',
            'previous_cgpa' => 'decimal:2',
            'previous_backlogs' => 'integer',
            'source' => AcademicRecordSource::class,
            'created_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ExcelImportBatch::class, 'import_id');
    }
}
