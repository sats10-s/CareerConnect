<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every faculty REASSIGNMENT on an
 * internship assignment - deliberately a separate table from
 * internship_assignment_status_history, since a faculty change is not
 * a status transition (the assignment's status is untouched by a
 * reassignment - see App\Services\InternshipAssignmentService::
 * reassignFaculty()) and hiding it inside a generic status/remarks
 * string would make "who coordinated this student, and when" much
 * harder to reconstruct. Per the module spec's explicit instruction:
 * "If a dedicated assignment history is insufficient to distinguish
 * faculty changes, add a dedicated reassignment history table rather
 * than hiding the event inside status history."
 *
 * `from_faculty_id` is nullable only in principle (every assignment
 * always has a faculty from creation onward, so in practice this is
 * always populated) - kept nullable for schema symmetry with
 * from_status in the sibling status-history table and to avoid ever
 * blocking a reassignment write on a faculty row that was since
 * removed; NULL-on-delete rather than restrict, so a faculty record
 * being removed later can never corrupt this audit trail, only lose
 * the identity of who it used to be. `to_faculty_id` is NOT nullable
 * (a reassignment always has a destination) and restrictOnDelete()
 * to keep that guarantee.
 *
 * The internship_assignment_id foreign key is given an explicit,
 * short constraint name (`ia_faculty_history_assignment_fk`) instead
 * of Laravel's auto-generated one. The auto-generated name would be
 * `internship_assignment_faculty_history_internship_assignment_id_foreign`
 * (70 characters), which exceeds MySQL's 64-character identifier
 * limit - the same class of error hit by the sibling
 * internship_assignment_status_history migration, fixed here
 * preemptively before it can fail the same way. Column, referenced
 * table/id, and cascadeOnDelete() behavior are unchanged - only the
 * constraint's name changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_assignment_faculty_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_assignment_id');
            $table->foreignId('from_faculty_id')->nullable()->constrained('faculty')->nullOnDelete();
            $table->foreignId('to_faculty_id')->constrained('faculty')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('internship_assignment_id', 'ia_faculty_history_assignment_fk')
                ->references('id')->on('internship_assignments')
                ->cascadeOnDelete();

            $table->index(['internship_assignment_id', 'created_at'], 'ia_faculty_history_assignment_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_assignment_faculty_history');
    }
};
