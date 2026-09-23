<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every application status change - see
 * App\Models\ApplicationStatusHistory and App\Services\ApplicationService
 * (the only writer of this table). Deliberately has no `updated_at`
 * (ApplicationStatusHistory::UPDATED_AT = null): a history row is
 * never modified after it is written, only ever inserted - this is
 * what "never overwrite previous history" means at the schema level.
 *
 * `from_status` is nullable to represent the very first row an
 * application ever gets (null -> APPLIED, written the moment the
 * application itself is created - see
 * App\Services\ApplicationService::apply()).
 *
 * `changed_by` is nullable and NULL-on-delete rather than restricted,
 * so a user account being removed later can never block deleting that
 * user or silently corrupt an unrelated application's history; the
 * history row itself is preserved either way (only the identity of
 * who made the change is lost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_history');
    }
};
