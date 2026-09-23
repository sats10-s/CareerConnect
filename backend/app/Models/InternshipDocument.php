<?php

namespace App\Models;

use App\Enums\InternshipDocumentStatus;
use App\Enums\InternshipDocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded document (offer letter, joining letter, certificates,
 * work evidence, final report, ...) against an internship assignment -
 * see the internship_documents migration's docblock and
 * App\Services\InternshipDocumentService (the only writer). No
 * student()/faculty() relation of its own, deliberately mirroring
 * App\Models\Attendance / App\Models\WeeklyProgressReport - ownership
 * is always derived by walking internshipAssignment ->
 * student_id/faculty_id, never a direct column on this model.
 *
 * `stored_filename`/`storage_path` are intentionally NOT cast or
 * exposed by any Resource in this module (see
 * InternshipDocumentResource's docblock) - the only sanctioned way to
 * read the file's bytes is
 * App\Services\InternshipDocumentService::retrieveForDownload(), never
 * a raw Storage::disk()->path()/->url() call built from client input.
 */
class InternshipDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'internship_assignment_id',
        'uploaded_by',
        'document_type',
        'title',
        'original_filename',
        'stored_filename',
        'storage_path',
        'mime_type',
        'file_size',
        'status',
        'student_notes',
        'faculty_review_remarks',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'changes_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => InternshipDocumentType::class,
            'status' => InternshipDocumentStatus::class,
            'file_size' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'changes_requested_at' => 'datetime',
        ];
    }

    public function internshipAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(InternshipDocumentStatusHistory::class);
    }
}
