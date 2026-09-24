<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel Sync Center - the field-level audit trail the module spec's
 * section 12 requires: "Every database change caused by an Excel
 * import must be recorded." Field list matches that section exactly
 * (id, import_batch_id, entity_type, entity_id, field, old_value,
 * new_value, changed_by, created_at - append-only, no updated_at, same
 * convention as every other *_status_history table in this codebase),
 * plus three reasoned additions needed for rollback (spec section 13)
 * to actually work, documented below.
 *
 * One row here is written per (entity, field) actually changed by a
 * CONFIRMED import - never for a row that failed/errored, and never
 * for a field that was already equal to its "new" value (a no-op is
 * not a change). This table, not excel_import_rows, is what
 * ExcelImportService::rollback() reads: excel_import_rows is a
 * preview/outcome cache scoped to one batch's parse-time snapshot,
 * while this table is the durable record of what a CONFIRMED import
 * actually wrote to the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_import_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('excel_import_batches')->cascadeOnDelete();

            // Reasoned addition: links back to the specific preview row
            // that produced this change, for traceability from a change
            // to the exact Excel row/raw data that caused it. Nullable
            // because a rollback-generated reversal change (see below)
            // has no excel_import_row of its own.
            $table->foreignId('excel_import_row_id')->nullable()->constrained('excel_import_rows')->nullOnDelete();

            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();

            // Reasoned addition: a change with old_value === null
            // represents a brand-new record's field (e.g. a freshly
            // created Attendance row) rather than an update to an
            // existing one - see ExcelImportService::rollback()'s
            // docblock for why creations are reported as
            // NOT_ROLLBACKABLE rather than silently deleted (no domain
            // service in this codebase exposes a delete path for
            // Attendance/Interview, and rollback must never bypass the
            // service layer any more than import itself may).
            $table->boolean('is_creation')->default(false);

            // Reasoned addition: this SAME table is where a successful
            // rollback's own reversal is recorded (module spec section
            // 13, "Rollback must itself be audited") - `is_rollback_of`
            // self-references the original change a rollback row
            // reverses, and `rolled_back_at` marks the ORIGINAL change
            // as consumed so it can never be rolled back a second time.
            $table->foreignId('is_rollback_of')->nullable()->constrained('excel_import_changes')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_batch_id'], 'excel_import_change_batch_idx');
            $table->index(['entity_type', 'entity_id'], 'excel_import_change_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_import_changes');
    }
};
