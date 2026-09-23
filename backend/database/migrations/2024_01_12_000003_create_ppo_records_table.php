<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Pre-Placement Offer (PPO) record per internship assignment - see
 * App\Models\PpoRecord and App\Services\InternshipCompletionService
 * (the only writer of this table). A PPO is entirely OPTIONAL - most
 * assignments will never have one; nothing elsewhere in this schema
 * requires a ppo_records row to exist.
 *
 * UNIQUENESS: at most ONE PPO record per assignment - enforced via a
 * PERMANENT unique index on internship_assignment_id, the DB-level
 * half of InternshipCompletionService::createPpo()'s own pre-insert
 * existence check, mirroring internship_completions'
 * (internship_assignment_id) uniqueness exactly (see that migration's
 * docblock for why a single-column unique key, not a composite one, is
 * correct here too).
 *
 * `company_id` is a SEPARATE FK column, not reached only by joining
 * through internship_assignment -> internship -> company - mirroring
 * how InternshipAssignment itself keeps its own denormalized
 * application_id/internship_id/student_id columns (see that model's
 * docblock) rather than requiring a multi-hop join for every query.
 * It is always DERIVED from the assignment's own internship.company_id
 * at creation time by InternshipCompletionService::createPpo() - never
 * accepted from the client (there is no `company_id` field on
 * StorePpoRecordRequest at all).
 *
 * `offered_at`/`joining_date` are plain DATE columns (calendar dates,
 * not timestamps) - matching completion_date's own type in
 * internship_completions and avoiding the exact "Eloquent's 'date'
 * cast writes a full datetime string on save" pitfall documented on
 * weekly_progress_reports' week_start_date/week_end_date.
 *
 * `package` is a nullable DECIMAL(12,2) - a CTC/stipend amount, not a
 * free-text field; App\Http\Requests\Ppo\StorePpoRecordRequest/
 * UpdatePpoRecordRequest validate it as `numeric|min:0`.
 *
 * `status` is backed by App\Enums\PpoStatus (OFFERED/ACCEPTED/
 * DECLINED) - stored as a short string, matching this schema's
 * existing ALL-CAPS backed-enum convention; defaults to OFFERED, the
 * only status a PPO record can ever be created with.
 *
 * MySQL 9.5 identifier-length rule: every foreign key/unique/regular
 * index on this table is given an explicit short name below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppo_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_assignment_id');
            $table->foreignId('company_id');
            $table->date('offered_at');
            $table->date('joining_date')->nullable();
            $table->string('designation', 255);
            $table->decimal('package', 12, 2)->nullable();
            $table->string('status', 20)->default('OFFERED');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('internship_assignment_id', 'ppo_record_assignment_fk')
                ->references('id')->on('internship_assignments')
                ->cascadeOnDelete();

            $table->foreign('company_id', 'ppo_record_company_fk')
                ->references('id')->on('companies')
                ->restrictOnDelete();

            $table->unique('internship_assignment_id', 'ppo_record_assignment_unique');
            $table->index('status', 'ppo_record_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppo_records');
    }
};
