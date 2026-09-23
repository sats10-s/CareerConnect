<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interview Management - one interview round belongs to exactly one
 * Application (never a standalone student interview - see
 * App\Models\Interview's docblock). A single application can have many
 * interview rows over its lifetime: each round is its own row, and a
 * reschedule updates THIS SAME row's `scheduled_at` (status becomes
 * RESCHEDULED, with the previous time preserved via a
 * interview_status_history remarks entry - see
 * App\Services\InterviewService::reschedule() for the full reasoning
 * behind this "one evolving record, history via the audit trail"
 * design, chosen over a "spawn a new interview row per reschedule"
 * alternative the module spec explicitly allowed as an option).
 *
 * Foreign keys:
 *  - application_id: cascadeOnDelete() - an interview cannot outlive
 *    its application (nothing in this app currently deletes an
 *    application, but if that ever changes, its interviews are not
 *    left as orphaned rows referencing nothing).
 *  - created_by: NOT NULL (every interview is created by some
 *    authenticated admin) - restrictOnDelete() rather than
 *    nullOnDelete(), since the column can't be nulled anyway; this
 *    just means a user account that has ever created an interview
 *    can't be hard-deleted out from under that record. No user-delete
 *    endpoint exists in this app today, so this is a safety default,
 *    not a behavior change.
 *  - updated_by: nullable (most interviews are never edited after
 *    creation) - nullOnDelete(), safe to lose just the "last editor"
 *    attribution if that user's account is later removed.
 *
 * scheduled_at (like every other timestamp in this schema) is stored
 * in UTC, matching config('app.timezone') = 'UTC' - see
 * App\Services\InterviewService for where incoming scheduled_at
 * values are normalized (Carbon::parse($value)->utc()) before being
 * persisted, regardless of what offset the client submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->string('title')->nullable();
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('mode', 20);
            $table->string('meeting_url', 500)->nullable();
            $table->string('venue')->nullable();
            $table->string('interviewer_name')->nullable();
            $table->string('interviewer_email')->nullable();
            $table->text('panel_notes')->nullable();
            $table->string('status', 20)->default('SCHEDULED');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('application_id');
            $table->index('status');
            $table->index('scheduled_at');
            $table->index('round');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
