<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One completion record per internship assignment - the faculty's
 * final assessment of whether the internship was actually completed,
 * plus an optional link to the certificate document that evidences it.
 * See App\Models\InternshipCompletion and
 * App\Services\InternshipCompletionService (the only writer of this
 * table). Scoped EXCLUSIVELY through InternshipAssignment, mirroring
 * Attendance/WeeklyProgressReport/InternshipDocument's own "no
 * student_id/faculty_id column of its own" rule - both are always
 * reached by joining through internship_assignment_id.
 *
 * UNIQUENESS: at most ONE completion record per assignment - enforced
 * here via a PERMANENT unique index on internship_assignment_id (not a
 * composite key - unlike attendance/weekly_progress_reports, which are
 * unique per (assignment, date)/(assignment, week), a completion record
 * has no second dimension: it is the single closing record for the
 * whole assignment). This is the DB-level half of
 * App\Services\InternshipCompletionService::create()'s own pre-insert
 * existence check - the same two-layer duplicate-prevention pattern
 * already used throughout this schema.
 *
 * `outcome` is backed by App\Enums\InternshipCompletionOutcome
 * (COMPLETED/NOT_COMPLETED) - the faculty's verdict, freely editable
 * while the record is still DRAFT. `status` is backed by
 * App\Enums\InternshipCompletionStatus (DRAFT/FINALIZED) - the
 * record's OWN lifecycle; see that enum's docblock for why this column
 * exists beyond the module spec's literal field list. `finalized_at`
 * is a nullable timestamp set exactly once, by
 * InternshipCompletionService::finalize() - mirrors how
 * internship_documents separates submitted_at/reviewed_at/approved_at/
 * changes_requested_at into their own independent nullable timestamp
 * columns rather than a single generic "status changed at".
 *
 * `completion_certificate_document_id` is a NULLABLE FK into
 * internship_documents - a completion record can exist (and even be
 * finalized) before a certificate is uploaded, since Internship
 * Document Management and Internship Completion are independent
 * modules with no ordering dependency between them (see
 * App\Services\InternshipCompletionService's docblock). nullOnDelete()
 * because losing the linked document (there is no delete route for one
 * today, but the FK should not block a future one) should never cascade
 * into destroying the completion record itself - it should simply
 * un-link.
 *
 * `completed_by` is a NOT NULL FK to `users` + restrictOnDelete(),
 * mirroring internship_documents.uploaded_by /
 * weekly_progress_report_status_history.changed_by exactly: losing who
 * recorded a still-referenced completion should never be silently
 * allowed by deleting that user account out from under it.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/unique/regular
 * index on this table is given an explicit short name below rather
 * than relying on Laravel's auto-generated ones, applied proactively
 * from the start (as in every module since Internship Assignment's own
 * `ia_status_history_assignment_fk` fix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_assignment_id');
            $table->date('completion_date');
            $table->string('outcome', 20);
            $table->text('final_remarks')->nullable();
            $table->foreignId('completion_certificate_document_id')->nullable();
            $table->foreignId('completed_by');
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->foreign('internship_assignment_id', 'internship_completion_assignment_fk')
                ->references('id')->on('internship_assignments')
                ->cascadeOnDelete();

            $table->foreign('completion_certificate_document_id', 'internship_completion_cert_doc_fk')
                ->references('id')->on('internship_documents')
                ->nullOnDelete();

            $table->foreign('completed_by', 'internship_completion_completed_by_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->unique('internship_assignment_id', 'internship_completion_assignment_unique');
            $table->index('status', 'internship_completion_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_completions');
    }
};
