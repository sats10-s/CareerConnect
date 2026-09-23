<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internship Assignment + Faculty Coordination - the faculty-
 * coordination relationship for a SELECTED internship application:
 * Student -> Internship opportunity -> Application -> SELECTED ->
 * Internship Assignment -> Faculty Coordinator. This table never
 * duplicates student/internship/company data - application_id,
 * internship_id and student_id are all FKs, and internship_id/
 * student_id are always DERIVED from the selected application at
 * creation time (see App\Services\InternshipAssignmentService::
 * create()), never trusted from the client, even though both are
 * stored as their own columns here (rather than requiring a join
 * through `applications` for every list/filter query - see section 7
 * of the module spec: admin list filtering by student/internship/
 * company must avoid N+1/expensive joins).
 *
 * Foreign keys (mirroring 2024_01_05_000001_create_interviews_table's
 * documented choices):
 *  - application_id: cascadeOnDelete() - an assignment cannot outlive
 *    its application.
 *  - internship_id / student_id: cascadeOnDelete() - mirrors
 *    applications.student_id and job_postings/internships.company_id's
 *    own cascadeOnDelete() choices.
 *  - faculty_id: restrictOnDelete() - an assignment must always
 *    reference a valid Faculty row; a faculty record can't be
 *    hard-deleted out from under an assignment (reassign first).
 *  - assigned_by: restrictOnDelete() - every assignment is created by
 *    some authenticated admin, mirroring interviews.created_by.
 *
 * `application_id` is UNIQUE - deliberately a permanent constraint,
 * not one scoped to "only while non-terminal". This mirrors
 * `applications_unique_per_student`'s own permanent uniqueness (a
 * student can never re-apply to an opportunity even after
 * withdrawing) rather than inventing a new conditional/partial-unique-
 * index pattern this schema doesn't otherwise use (MySQL has no
 * WHERE-filtered unique index the way Postgres does, and this schema
 * targets MySQL in production / SQLite in tests). Practically: an
 * application gets AT MOST ONE internship_assignment row, ever. This
 * is also exactly what section 9 of the module spec requires
 * regardless ("do not create a second assignment row for the same
 * application" - reassigning faculty always updates this same row via
 * PATCH .../faculty), so the permanent constraint costs nothing and
 * directly enforces "duplicate active assignment blocked" at the
 * database level, not just in the service.
 *
 * `status` is a string column backed by the PHP enum
 * App\Enums\InternshipAssignmentStatus (ASSIGNED/ACTIVE/COMPLETED/
 * CANCELLED), matching the newer ALL-CAPS enum convention this schema
 * already uses for ApplicationStatus/InterviewStatus/
 * PlacementDriveStatus - not the older lowercase OpportunityStatus one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('applications')->cascadeOnDelete();
            $table->foreignId('internship_id')->constrained('internships')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('faculty_id')->constrained('faculty')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->string('status', 20)->default('ASSIGNED');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('internship_id');
            $table->index('student_id');
            $table->index('faculty_id');
            $table->index('status');
            $table->index('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_assignments');
    }
};
