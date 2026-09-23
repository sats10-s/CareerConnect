<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for ppo_records.status - see
 * App\Models\PpoRecordStatusHistory and
 * App\Services\InternshipCompletionService::recordPpoHistory() (the
 * only writer of this table; there is no update/delete route anywhere
 * in this API for it). Mirrors internship_completion_status_history /
 * internship_document_status_history exactly: `changed_by` is NOT
 * NULL + restrictOnDelete(), and there is no `updated_at` column at
 * all (see App\Models\PpoRecordStatusHistory's `UPDATED_AT = null`).
 *
 * Exactly two rows are ever possible per PPO record over its lifetime:
 * the initial null -> OFFERED row written at creation, and a single
 * OFFERED -> {ACCEPTED | DECLINED} row written when the student's
 * response is recorded (both ACCEPTED and DECLINED are terminal - see
 * PpoStatus::isTerminal() - so no third row is ever possible). A plain
 * content edit while still OFFERED (changing designation/package/
 * joining_date/remarks/offered_at) is NOT a status change and does not
 * write a history row here.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/index on this
 * table is given an explicit short name below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppo_record_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppo_record_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ppo_record_id', 'ppo_record_history_record_fk')
                ->references('id')->on('ppo_records')
                ->cascadeOnDelete();

            $table->foreign('changed_by', 'ppo_record_history_user_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index(['ppo_record_id', 'created_at'], 'ppo_record_history_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppo_record_status_history');
    }
};
