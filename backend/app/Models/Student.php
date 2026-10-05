<?php

namespace App\Models;

use App\Enums\EntryType;
use App\Enums\Gender;
use App\Enums\InterType;
use App\Enums\Program;
use App\Enums\SscScale;
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
        'year',
        'section',
        'gender',
        'year_of_joining',
        'entry_type',
        'program',
        'cgpa',
        'backlogs',
        'ssc_score',
        'ssc_scale',
        'inter_score',
        'inter_type',
        'academics_updated_at',
        'phone',
        'faculty_mentor_id',
        'personal_email',
        'linkedin_url',
        'github_url',
        'portfolio_url',
        'address',
        'resume_path',
        'resume_original_name',
        'resume_size',
        'resume_uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'cgpa' => 'decimal:2',
            'backlogs' => 'integer',
            'gender' => Gender::class,
            'year_of_joining' => 'integer',
            'entry_type' => EntryType::class,
            'program' => Program::class,
            'ssc_score' => 'decimal:2',
            'ssc_scale' => SscScale::class,
            'inter_score' => 'decimal:2',
            'inter_type' => InterType::class,
            'academics_updated_at' => 'datetime',
            'resume_size' => 'integer',
            'resume_uploaded_at' => 'datetime',
        ];
    }

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

    /**
     * Internship Assignment + Faculty Coordination - every
     * InternshipAssignment row belonging to this student, across
     * however many internship applications they've had. Distinct from
     * facultyMentor()/faculty_mentor_id above, which is a pre-existing,
     * unrelated general mentorship assignment - this is specifically
     * the faculty-coordination relationship for a SELECTED internship
     * application (see App\Models\InternshipAssignment's docblock).
     */
    public function internshipAssignments(): HasMany
    {
        return $this->hasMany(InternshipAssignment::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(StudentSkill::class);
    }

    /** CGPA/backlogs history, newest first. */
    public function academicRecords(): HasMany
    {
        return $this->hasMany(StudentAcademicRecord::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function correctionRequests(): HasMany
    {
        return $this->hasMany(CorrectionRequest::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(StudentProject::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(StudentCertification::class);
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(StudentExperience::class);
    }
}
