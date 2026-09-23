<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    use HasFactory;

    protected $table = 'faculty';

    protected $fillable = [
        'user_id',
        'employee_code',
        'department_id',
        'designation',
        'phone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Students mentored by this faculty member. */
    public function mentees(): HasMany
    {
        return $this->hasMany(Student::class, 'faculty_mentor_id');
    }

    /**
     * Internship Assignment + Faculty Coordination - every
     * InternshipAssignment row this faculty member coordinates. This
     * is the SOLE source of truth for "this faculty's students" (see
     * App\Http\Controllers\Api\Faculty\StudentController - the module
     * spec explicitly says not to create a separate faculty-student
     * relationship table); distinct from mentees() above, which is the
     * pre-existing, unrelated general mentorship relation.
     */
    public function internshipAssignments(): HasMany
    {
        return $this->hasMany(InternshipAssignment::class);
    }
}
