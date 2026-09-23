<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for internship_documents.status - see
 * App\Models\InternshipDocumentStatusHistory and
 * App\Services\InternshipDocumentService::recordHistory() (the only
 * writer of this table; there is no update/delete route anywhere in
 * this API for it - see the module's controllers). Mirrors
 * weekly_progress_report_status_history exactly: `changed_by` is NOT
 * NULL + restrictOnDelete() (unlike attendance_history.changed_by,
 * which is nullable), and there is no `updated_at` column at all (see
 * App\Models\InternshipDocumentStatusHistory's `UPDATED_AT = null`).
 *
 * Every actual status transition (SUBMITTED -> UNDER_REVIEW,
 * UNDER_REVIEW -> APPROVED, UNDER_REVIEW -> CHANGES_REQUESTED,
 * CHANGES_REQUESTED -> SUBMITTED, and the initial null -> SUBMITTED on
 * first upload) writes exactly one row here - a content-only change
 * (there isn't one for documents beyond replacing the file itself,
 * which the resubmit() transition already covers) never does.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/index on this
 * table is given an explicit short name below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_document_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_document_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('internship_document_id', 'internship_doc_history_doc_fk')
                ->references('id')->on('internship_documents')
                ->cascadeOnDelete();

            $table->foreign('changed_by', 'internship_doc_history_user_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index(['internship_document_id', 'created_at'], 'internship_doc_history_doc_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_document_status_history');
    }
};
