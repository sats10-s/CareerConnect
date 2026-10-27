<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StudentPlacementStatusHistory extends Model
{
    protected $table = 'student_placement_status_history';

    protected $fillable = [
        'student_id', 'from_status', 'to_status', 'old_details', 'new_details',
        'reason', 'changed_by', 'changed_at',
    ];

    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    protected function casts(): array
    {
        return [
            'old_details' => 'array',
            'new_details' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    /** History is written through StudentPlacementStatusService and cannot be edited. */
    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Placement status history is append-only and cannot be updated.');
    }

    /** History is retained as an audit record and cannot be deleted through the model. */
    protected function performDeleteOnModel()
    {
        throw new LogicException('Placement status history is append-only and cannot be deleted.');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
