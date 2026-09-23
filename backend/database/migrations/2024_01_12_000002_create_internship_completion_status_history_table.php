<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for internship_completions.status - see
 * App\Models\InternshipCompletionStatusHistory and
 * App\Services\InternshipCompletionService::recordCompletionHistory()
 * (the only writer of this table; there is no update/delete route
 * anywhere in this API for it). Mirrors
 * internship_document_status_history exactly: `changed_by` is NOT
 * NULL + restrictOnDelete(), and there is no `updated_at` column at
 * all (see App\Models\InternshipCompletionStatusHistory's
 * `UPDATED_AT = null`).
 *
 * Exactly two possible rows exist per completion over its lifetime:
 * the initial null -> DRAFT row written at creation, and the single
 * DRAFT -> FINALIZED row written by finalize() (FINALIZED is terminal -
 * see InternshipCompletionStatus::isTerminal() - so no third row is
 * ever possible). A plain content edit while still DRAFT (changing
 * outcome/final_remarks/completion_date/the linked certificate) is NOT
 * a status change and does not write a history row here - exactly the
 * same rule InternshipDocumentService already applies to its own
 * content-only edits.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/index on this
 * table is given an explicit short name below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_completion_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_completion_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('internship_completion_id', 'internship_completion_history_completion_fk')
                ->references('id')->on('internship_completions')
                ->cascadeOnDelete();

            $table->foreign('changed_by', 'internship_completion_history_user_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index(['internship_completion_id', 'created_at'], 'internship_completion_history_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_completion_status_history');
    }
};
