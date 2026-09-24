<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel Sync Center - one row per Excel data row within a batch,
 * created once during upload/parse/match/validate and never re-parsed
 * from the raw file again afterwards. This table is NOT named in the
 * module spec's literal schema (sections 11/12 only name
 * excel_import_batches/excel_import_changes) but is a necessary piece
 * of infrastructure the spec's own workflow requires: section 9 says
 * "After upload: DO NOT modify MySQL... Show: Excel Import Preview"
 * and section 10's confirmation step acts on exactly what the admin
 * was shown in that preview - both need somewhere to durably hold the
 * parsed/matched/validated result of every row between upload and
 * confirm, without re-reading the uploaded file or re-running matching
 * logic against whatever MySQL state happens to exist at confirm time
 * (which could have drifted since the file was parsed).
 *
 * `raw_row`/`changes`/`messages` are JSON rather than several extra
 * narrow columns - a single-field status update (Application) and a
 * multi-field Student Master update both need to describe an arbitrary
 * number of {field, old_value, new_value} triples, and no fixed column
 * set could hold both without being sparse for one or the other.
 *
 * `entity_type`/`entity_id` deliberately are NOT a real Eloquent
 * MorphTo pair (no morph map, no polymorphic relation defined on this
 * model) - they only ever need to be read back out as plain values to
 * build a preview row or to know which record confirm() must act on;
 * see App\Models\ExcelImportRow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('excel_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            // The Excel row as parsed into a normalized column => value
            // map (header names normalized, values trimmed) - the
            // source of truth for what the admin's preview screen shows
            // under "Excel" per row, and for confirm() to re-derive
            // exactly what was matched without re-opening the file.
            $table->json('raw_row');

            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            // Extra data needed only at confirm() time that doesn't fit
            // the {field, old_value, new_value} shape of `changes` -
            // e.g. the resolved target ApplicationStatus enum value, or
            // an interview's resolved round number. Never trusted blindly:
            // confirm() re-validates every one of these against the
            // CURRENT database state before acting (see
            // ExcelImportService::confirm()'s docblock).
            $table->json('confirm_context')->nullable();

            $table->string('outcome', 10);
            $table->json('messages');
            $table->json('changes');

            $table->boolean('applied')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->text('apply_error')->nullable();

            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number'], 'excel_import_row_batch_row_unique');
            $table->index(['import_batch_id', 'outcome'], 'excel_import_row_batch_outcome_idx');
            $table->index(['entity_type', 'entity_id'], 'excel_import_row_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_import_rows');
    }
};
