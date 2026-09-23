<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded internship document (offer letter, joining
 * letter, certificates, work evidence, final report, ...) - see
 * App\Models\InternshipDocument and App\Services\InternshipDocumentService
 * (the only writer of this table). Scoped EXCLUSIVELY through
 * InternshipAssignment - Student -> Selected Internship Application ->
 * InternshipAssignment -> Faculty Coordinator -> Internship Document -
 * mirroring Attendance/WeeklyProgressReport's own "no student_id/
 * faculty_id column of its own" rule (see App\Models\InternshipDocument's
 * docblock); both are always reached by joining through
 * internship_assignment_id.
 *
 * `uploaded_by` is a separate FK to `users` (not necessarily the
 * assignment's own student - a faculty/admin re-upload is not part of
 * this increment, but the column is deliberately generic rather than
 * named student_id, matching how the spec phrases it) - NOT NULL +
 * restrictOnDelete(), mirroring weekly_progress_report_status_history.changed_by:
 * losing who uploaded a still-referenced document should never be
 * silently allowed by deleting that user account out from under it.
 *
 * `document_type` is backed by App\Enums\InternshipDocumentType (purely
 * descriptive/filterable - no transition rules); `status` is backed by
 * App\Enums\InternshipDocumentStatus (SUBMITTED/UNDER_REVIEW/APPROVED/
 * CHANGES_REQUESTED) - both stored as short strings, matching this
 * schema's existing ALL-CAPS backed-enum convention.
 *
 * SECURITY - the actual file is never publicly reachable: `storage_path`
 * points into the `local` filesystem disk (storage/app/private/...,
 * see config/filesystems.php - no symlink into public/, no public URL),
 * and `stored_filename` is a random, server-generated name (see
 * InternshipDocumentService::storeFile()) - never derived from
 * `original_filename`, which is kept purely as a display value and is
 * never used to build a filesystem path. Both `stored_filename` and
 * `storage_path` are deliberately excluded from every student/faculty-
 * facing Resource (see InternshipDocumentResource's docblock) - a
 * client never sees, and can therefore never manipulate, where a file
 * actually lives on disk.
 *
 * `submitted_at`/`reviewed_at`/`approved_at`/`changes_requested_at` are
 * independent nullable timestamps (not a single generic "status changed
 * at") so each lifecycle milestone can be queried/displayed on its own,
 * matching how weekly_progress_reports separates
 * student_submitted_at/faculty_reviewed_at rather than collapsing them.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/index on this
 * table is given an explicit short name below rather than relying on
 * Laravel's auto-generated ones - see the Internship Assignment
 * module's own `ia_status_history_assignment_fk` fix for why long
 * generated names are avoided entirely here from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_assignment_id');
            $table->foreignId('uploaded_by');
            $table->string('document_type', 30);
            $table->string('title', 255);
            $table->string('original_filename', 255);
            $table->string('stored_filename', 255);
            $table->string('storage_path', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->string('status', 20)->default('SUBMITTED');
            $table->text('student_notes')->nullable();
            $table->text('faculty_review_remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('changes_requested_at')->nullable();
            $table->timestamps();

            $table->foreign('internship_assignment_id', 'internship_document_assignment_fk')
                ->references('id')->on('internship_assignments')
                ->cascadeOnDelete();

            $table->foreign('uploaded_by', 'internship_document_uploaded_by_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index('status', 'internship_document_status_idx');
            $table->index('document_type', 'internship_document_type_idx');
            $table->index(['internship_assignment_id', 'document_type'], 'internship_document_assignment_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_documents');
    }
};
