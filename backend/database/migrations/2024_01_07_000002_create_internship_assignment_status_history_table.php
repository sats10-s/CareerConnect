<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every internship assignment STATUS
 * change (create/activate/complete/cancel) - see
 * App\Models\InternshipAssignmentStatusHistory and
 * App\Services\InternshipAssignmentService (the only writer of this
 * table). No `updated_at` (UPDATED_AT = null on the model) - a history
 * row is only ever inserted, never modified, mirroring
 * application_status_history/interview_status_history/
 * placement_drive_status_history exactly.
 *
 * `from_status` is nullable for the very first row an assignment ever
 * gets (null -> ASSIGNED, written the moment the assignment is
 * created).
 *
 * Faculty REASSIGNMENT is deliberately NOT recorded here - see
 * internship_assignment_faculty_history, a dedicated table, so a
 * faculty change is never hidden inside a generic status/remarks
 * string (per the module spec's explicit instruction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_assignment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_assignment_id')->constrained('internship_assignments')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['internship_assignment_id', 'created_at'], 'ia_status_history_assignment_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_assignment_status_history');
    }
};
