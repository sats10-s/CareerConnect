<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every placement drive status change -
 * mirrors application_status_history/interview_status_history exactly
 * (no `updated_at`, changed_by nullOnDelete). App\Services\
 * PlacementDriveService is the only writer. Initial creation writes a
 * null -> DRAFT row, same as the other two history tables' null ->
 * initial-status convention.
 *
 * The composite index below is given an explicit short name for the
 * same MySQL 64-character identifier reason as the sibling migration
 * in this batch (this table's own name, 31 chars, pushes the default
 * auto-generated name for a 2-column index past the limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_drive_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_drive_id')->constrained('placement_drives')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['placement_drive_id', 'created_at'], 'pdsh_drive_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_drive_status_history');
    }
};
